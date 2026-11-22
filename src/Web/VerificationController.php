<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use SecurityFlow\Security\SecurityFlowAnalyzer;
use SecurityFlow\Visualization\GraphSerializer;
use SecurityFlow\Visualization\VerificationPresenter;
use Throwable;

final readonly class VerificationController
{
    public function __construct(
        private ModelCatalog $models,
        private GraphSerializer $graphs = new GraphSerializer(),
        private VerificationPresenter $presenter = new VerificationPresenter(),
    ) {
    }

    public function inspect(string $id, ?string $focus = null): array
    {
        $entry = $this->models->load($id);
        $model = $entry['model'];
        if ($focus !== null && !isset($model->states[$focus])) {
            throw new HttpError(404, 'The selected state does not exist in this model.');
        }
        return [
            'meta' => $entry['meta'],
            'model' => $this->presenter->describe($model),
            'graph' => $this->graphs->view($model, focus: $focus),
        ];
    }

    public function run(array $options): array
    {
        $id = $options['model'];
        $run = ['id' => 'r-' . bin2hex(random_bytes(8)), 'created_at' => gmdate('c'), 'catalog_id' => $id];
        try {
            $entry = $this->models->load($id);
            $model = $entry['model'];
            $graphIndex = [];
            $math = [];
            $report = (new SecurityFlowAnalyzer())->analyze(
                $model,
                $options['max_depth'],
                $options['max_states'],
                $options['max_transitions'],
                function (array $reach, array $checks, array $paths) use ($model, &$graphIndex, &$math): void {
                    $graphIndex = $this->graphs->index($model, $reach, $checks, $paths);
                    $math = $this->presenter->mathematics($model, $reach, $checks);
                },
            );
            $report['status'] = $report['status']->value;
            $report['http_replay_status'] = 'NOT_RUN';
            $start = $report['findings'][0]['counterexample']['initial_state'] ?? null;

            return $run + [
                'meta' => $entry['meta'],
                'model' => $this->presenter->describe($model),
                'fingerprint' => $entry['fingerprint'],
                'report' => $report,
                'graph' => $this->graphs->view($model, $graphIndex, start: $start),
                'graph_index' => $graphIndex,
                'mathematics' => $math,
            ];
        } catch (HttpError $error) {
            throw $error;
        } catch (Throwable) {
            return $run + ['report' => [
                'model' => $id,
                'status' => 'ERROR',
                'analysis_complete' => false,
                'findings' => [],
                'http_replay_status' => 'NOT_RUN',
                'runtime_reproduced' => false,
                'error' => 'Verification could not complete for this bundled model.',
            ]];
        }
    }

    public function graph(array $run, ?string $focus, ?string $property): array
    {
        if (!isset($run['graph_index'])) {
            throw new HttpError(404, 'This run has no graph.');
        }
        $entry = $this->models->load($run['catalog_id']);
        if ($entry['fingerprint'] !== $run['fingerprint']) {
            throw new HttpError(409, 'The model changed after this run. Rerun it to explore other neighborhoods.');
        }
        $model = $entry['model'];
        if ($focus !== null && !isset($model->states[$focus])) {
            throw new HttpError(404, 'The selected state does not exist in this model.');
        }
        $finding = $run['report']['findings'][0] ?? null;
        if ($property !== null) {
            $finding = null;
            foreach ($run['report']['findings'] as $candidate) {
                if ($candidate['property_id'] === $property) {
                    $finding = $candidate;
                    break;
                }
            }
            if ($finding === null) {
                throw new HttpError(404, 'This run has no counterexample for that property.');
            }
        }
        return $this->graphs->view(
            $model,
            $run['graph_index'],
            $focus,
            $finding['property_id'] ?? null,
            $finding['counterexample']['initial_state'] ?? null,
        );
    }
}
