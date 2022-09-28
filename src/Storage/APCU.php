<?php


namespace U9\U9PhpPrometheus\Storage;

use Illuminate\Support\Facades\Log;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use RuntimeException;

class APCU implements Adapter
{
    private $prometheusPrefix = 'prom';

    public function __construct()
    {
        $this->prometheusPrefix .= config('prometheus-exporter.adapter_key_prefix');
    }


    /**
     * @return MetricFamilySamples[]
     */
    public function collect()
    {
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges());
        $metrics = array_merge($metrics, $this->collectCounters());
        return $metrics;
    }

    /**
     * @return array
     */
    private function collectHistograms()
    {
        $histograms = array();
        foreach (new \APCUIterator('/^' . $this->prometheusPrefix . ':histogram:.*:meta/') as $histogram) {
            $metaData = json_decode($histogram['value'], true);
            $data = array(
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'buckets' => $metaData['buckets']
            );

            // Add the Inf bucket so we can compute it later on
            $data['buckets'][] = '+Inf';

            $histogramBuckets = array();
            foreach (new \APCUIterator('/^' . $this->prometheusPrefix . ':histogram:' . $metaData['name'] . ':.*:value/') as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $bucket = $parts[4];
                // Key by labelValues
                $histogramBuckets[$labelValues][$bucket] = $value['value'];
            }

            // Compute all buckets
            $labels = array_keys($histogramBuckets);
            sort($labels);
            foreach ($labels as $labelValues) {
                $acc = 0;
                $decodedLabelValues = $this->decodeLabelValues($labelValues);
                foreach ($data['buckets'] as $bucket) {
                    $bucket = (string)$bucket;
                    if (!isset($histogramBuckets[$labelValues][$bucket])) {
                        $data['samples'][] = array(
                            'name' => $metaData['name'] . '_bucket',
                            'labelNames' => array('le'),
                            'labelValues' => array_merge($decodedLabelValues, array($bucket)),
                            'value' => $acc
                        );
                    } else {
                        $acc += $histogramBuckets[$labelValues][$bucket];
                        $data['samples'][] = array(
                            'name' => $metaData['name'] . '_' . 'bucket',
                            'labelNames' => array('le'),
                            'labelValues' => array_merge($decodedLabelValues, array($bucket)),
                            'value' => $acc
                        );
                    }
                }

                // Add the count
                $data['samples'][] = array(
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => array(),
                    'labelValues' => $decodedLabelValues,
                    'value' => $acc
                );

                // Add the sum
                $data['samples'][] = array(
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => array(),
                    'labelValues' => $decodedLabelValues,
                    'value' => $this->fromInteger($histogramBuckets[$labelValues]['sum']) / 1000.0
                );
            }
            $histograms[] = new MetricFamilySamples($data);
        }
        return $histograms;
    }

    /**
     * @param string $values
     * @return array
     * @throws RuntimeException
     */
    private function decodeLabelValues($values)
    {
        $json = base64_decode($values, true);
        if (false === $json) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if (false === $decodedValues) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $decodedValues;
    }

    /**
     * @param mixed $val
     * @return int
     */
    private function fromInteger($val)
    {
        return unpack('d', pack('Q', $val))[1];
    }

    /**
     * @return array
     */
    private function collectGauges()
    {
        $gauges = array();
        foreach (new \APCUIterator('/^' . $this->prometheusPrefix . ':gauge:.*:meta/') as $gauge) {
            $metaData = json_decode($gauge['value'], true);
            $data = array(
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
            );
            foreach (new \APCUIterator('/^' . $this->prometheusPrefix . ':gauge:' . $metaData['name'] . ':.*:value/') as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = array(
                    'name' => $metaData['name'],
                    'labelNames' => array(),
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->fromInteger($value['value'])
                );
            }

            $this->sortSamples($data['samples']);
            $gauges[] = new MetricFamilySamples($data);
        }
        return $gauges;
    }

    private function sortSamples(array &$samples)
    {
        usort($samples, function ($a, $b) {
            return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
        });
    }

    /**
     * @return array
     */
    private function collectCounters()
    {
        $counters = array();
        foreach (new \APCUIterator('/^' . $this->prometheusPrefix . ':counter:.*:meta/') as $counter) {
            $metaData = json_decode($counter['value'], true);
            $data = array(
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
            );
            foreach (new \APCUIterator('/^' . $this->prometheusPrefix . ':counter:' . $metaData['name'] . ':.*:value/') as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = array(
                    'name' => $metaData['name'],
                    'labelNames' => array(),
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $value['value']
                );
            }
            $this->sortSamples($data['samples']);
            $counters[] = new MetricFamilySamples($data);
        }
        return $counters;
    }

    public function updateHistogram(array $data)
    {
        // Initialize the sum
        $sumKey = $this->histogramBucketValueKey($data, 'sum');
        $new = apcu_add($sumKey, $this->toInteger(0));

        // If sum does not exist, assume a new histogram and store the metadata
        if ($new) {
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
        }

        // Atomically increment the sum
        // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
        $done = $this->incrValueAPC($sumKey, $data['value'] * 1000.0);
        if (!$done) {
            return false;
        }

        // Figure out in which bucket the observation belongs
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }

        // Initialize and increment the bucket
        apcu_add($this->histogramBucketValueKey($data, $bucketToIncrease), 0);
        apcu_inc($this->histogramBucketValueKey($data, $bucketToIncrease));
    }

    /**
     * @param array $data
     * @return string
     */
    private function histogramBucketValueKey(array $data, $bucket)
    {
        return implode(':', array(
            $this->prometheusPrefix,
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            $bucket,
            'value'
        ));
    }

    /**
     * @param array $values
     * @return string
     * @throws RuntimeException
     */
    private function encodeLabelValues(array $values)
    {
        $json = json_encode($values);
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        return base64_encode($json);
    }

    /**
     * @param mixed $val
     * @return int
     */
    private function toInteger($val)
    {
        return unpack('Q', pack('d', $val))[1];
    }

    /**
     * @param array $data
     * @return string
     */
    private function metaKey(array $data)
    {
        return implode(':', array($this->prometheusPrefix, $data['type'], $data['name'], 'meta'));
    }

    /**
     * @param array $data
     * @return array
     */
    private function metaData(array $data)
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value']);
        unset($metricsMetaData['command']);
        unset($metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    /**
     * 累加Prometheus统计数据
     * @param $key
     * @param $incr
     * @return bool
     */
    private function incrValueAPC($key, $incr)
    {
        $done = false;
        $old = null;
        //最多尝试3次
        for ($count = 0; $count < 3; $count++) {
            $old = apcu_fetch($key);
            $done = apcu_cas($key, $old, $this->toInteger($this->fromInteger($old) + $incr));
            if ($done) {
                return $done;
            }
        }
        $errorInfo = sprintf("Prometheus APCU 数据更新失败：key=%s old=%s increment=%s", $key, $old, $incr);
        Log::error($errorInfo);
        return $done;
    }

    public function updateGauge(array $data)
    {
        $valueKey = $this->valueKey($data);
        if ($data['command'] == Adapter::COMMAND_SET) {
            apcu_store($valueKey, $this->toInteger($data['value']));
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
        } else {
            $new = apcu_add($valueKey, $this->toInteger(0));
            if ($new) {
                apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
            }
            // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
            return $this->incrValueAPC($valueKey, $data['value']);
        }
    }

    /**
     * @param array $data
     * @return string
     */
    private function valueKey(array $data)
    {
        return implode(':', array(
            $this->prometheusPrefix,
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value'
        ));
    }

    public function updateCounter(array $data)
    {
        $new = apcu_add($this->valueKey($data), 0);
        if ($new) {
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
        }
        apcu_inc($this->valueKey($data), $data['value']);
    }

    public function flushAPC()
    {
        apcu_clear_cache();
    }
}
