<?php
namespace U9\U9PhpPrometheus\Controller;

use Illuminate\Http\Response;
use Laravel\Lumen\Routing\Controller;
use Prometheus\RenderTextFormat;
use U9\U9PhpPrometheus\Contract\PrometheusExporterContract;
use U9\U9PhpPrometheus\PrometheusExporter;

class PrometheusExporterController extends Controller
{
    /**
     * @var PrometheusExporter
     */
    protected $prometheusExporter;

    /**
     * PrometheusExporterController constructor.
     *
     * @param PrometheusExporterContract $prometheusExporter
     */
    public function __construct(PrometheusExporterContract $prometheusExporter)
    {
        $this->prometheusExporter = $prometheusExporter;
    }

    /**
     * metrics
     *
     * Expose metrics for prometheus
     *
     * @return Response
     */
    public function metrics()
    {
        $renderer = new RenderTextFormat();

        return Response::create(
            $renderer->render($this->prometheusExporter->getMetricFamilySamples())
        )->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }
}
