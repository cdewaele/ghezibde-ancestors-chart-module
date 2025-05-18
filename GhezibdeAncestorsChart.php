<?php

declare(strict_types=1);

namespace GhezibdeAncestorsChart;

use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ChartService;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;

use function route;
use function redirect;

class GhezibdeAncestorsChart extends AbstractModule implements ModuleCustomInterface, ModuleChartInterface, RequestHandlerInterface
{
    use ModuleCustomTrait;
    use ModuleChartTrait;

    protected const ROUTE_URL = '/tree/{tree}/ancestors-{style}-{generations}/{xref}';

    public const CHART_STYLE_TREE        = 'tree';
    public const CHART_STYLE_INDIVIDUALS = 'individuals';
    public const CHART_STYLE_FAMILIES    = 'families';

    // modification : default number of generations is 5
    // public const DEFAULT_GENERATIONS = '4';
    public const DEFAULT_GENERATIONS = '5';
    // modification : default style is individuals
    // public const DEFAULT_STYLE       = self::CHART_STYLE_TREE;
    public const DEFAULT_STYLE       = self::CHART_STYLE_INDIVIDUALS;
    protected const DEFAULT_PARAMETERS = [
        'generations' => self::DEFAULT_GENERATIONS,
        'style'       => self::DEFAULT_STYLE,
    ];

    protected const MINIMUM_GENERATIONS = 2;
    // Modification : maximum generations is 17
    // protected const MAXIMUM_GENERATIONS = 10;
    protected const MAXIMUM_GENERATIONS = 17;

    public function boot(): void
    {
        Registry::routeFactory()->routeMap()
            ->get(static::class, static::ROUTE_URL, $this)
            ->allows(RequestMethodInterface::METHOD_POST)
            ->tokens([
                'generations' => '\d+',
                'style'       => implode('|', array_keys($this->styles())),
            ]);
    }

    public function title(): string
    {
        return I18N::translate('Ancestors');
    }

    public function description(): string
    {
        return I18N::translate('A chart of an individual’s ancestors.');
    }

    public function chartMenuClass(): string
    {
        return 'menu-chart-ancestry';
    }

    public function chartBoxMenu(Individual $individual): ?Menu
    {
        return $this->chartMenu($individual);
    }

    public function chartTitle(Individual $individual): string
    {
        return I18N::translate('Ancestors of %s', $individual->fullName());
    }

    public function chartUrl(Individual $individual, array $parameters = []): string
    {
        return route(static::class, [
            'xref' => $individual->xref(),
            'tree' => $individual->tree()->name(),
        ] + $parameters + self::DEFAULT_PARAMETERS);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree        = Validator::attributes($request)->tree();
        $user        = Validator::attributes($request)->user();
        $style       = Validator::attributes($request)->isInArrayKeys($this->styles())->string('style');
        $xref        = Validator::attributes($request)->isXref()->string('xref');
        $generations = Validator::attributes($request)->isBetween(self::MINIMUM_GENERATIONS, self::MAXIMUM_GENERATIONS)->integer('generations');
        $ajax        = Validator::queryParams($request)->boolean('ajax', false);

        if ($request->getMethod() === RequestMethodInterface::METHOD_POST) {
            return redirect(route(static::class, [
                'tree'        => $tree->name(),
                'xref'        => Validator::parsedBody($request)->isXref()->string('xref'),
                'style'       => Validator::parsedBody($request)->isInArrayKeys($this->styles())->string('style'),
                'generations' => Validator::parsedBody($request)->isBetween(self::MINIMUM_GENERATIONS, self::MAXIMUM_GENERATIONS)->integer('generations'),
            ]));
        }

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        if ($ajax) {
            $this->layout = 'layouts/ajax';

            // Instancier ChartService directement quand nécessaire
            $chart_service = new ChartService();
            $ancestors = $chart_service->sosaStradonitzAncestors($individual, $generations);

            switch ($style) {
                case self::CHART_STYLE_TREE:
                    return $this->viewResponse('modules/ghezibde-ancestors-chart/tree', [
                        'individual'  => $individual,
                        'parents'     => $individual->childFamilies()->first(),
                        'generations' => $generations,
                        'sosa'        => 1,
                    ]);

                case self::CHART_STYLE_INDIVIDUALS:
                    return $this->viewResponse('lists/individuals-table', [
                        'individuals' => $ancestors,
                        'sosa'        => true,
                        'tree'        => $tree,
                    ]);

                case self::CHART_STYLE_FAMILIES:
                    $families = [];

                    foreach ($ancestors as $individual) {
                        foreach ($individual->childFamilies() as $family) {
                            $families[$family->xref()] = $family;
                        }
                    }

                    return $this->viewResponse('lists/families-table', [
                        'families' => $families,
                        'tree'     => $tree,
                    ]);
            }
        }

        $ajax_url = $this->chartUrl($individual, [
            'ajax'        => true,
            'generations' => $generations,
            'style'       => $style,
            'xref'        => $xref,
        ]);

        return $this->viewResponse('modules/ancestors-chart/page', [
            'ajax_url'            => $ajax_url,
            'generations'         => $generations,
            'individual'          => $individual,
            'maximum_generations' => self::MAXIMUM_GENERATIONS,
            'minimum_generations' => self::MINIMUM_GENERATIONS,
            'module'              => $this->name(),
            'style'               => $style,
            'styles'              => $this->styles(),
            'title'               => $this->chartTitle($individual),
            'tree'                => $tree,
        ]);
    }

    protected function styles(): array
    {
        return [
            self::CHART_STYLE_TREE        => I18N::translate('Tree'),
            self::CHART_STYLE_INDIVIDUALS => I18N::translate('Individuals'),
            self::CHART_STYLE_FAMILIES    => I18N::translate('Families'),
        ];
    }
    /**
     * The person or organisation who created this module.
     *
     * @return string
     */
    public function customModuleAuthorName(): string
    {
        return 'Ghezibde';
    }

    /**
     * The version of this module.
     *
     * @return string
     */
    public function customModuleVersion(): string
    {
        return '2.1.16.0';
    }

    /**
     * A URL that will provide the latest version of this module.
     *
     * @return string
     */
    public function customModuleLatestVersionUrl(): string
    {
        return 'https://github.com/cdewaele/ghezibde-ancestors-chart/raw/main/latest-version.txt';
    }
}
