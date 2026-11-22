<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use Throwable;

final readonly class DashboardController
{
    private VerificationController $verification;

    public function __construct(private ModelCatalog $models)
    {
        $this->verification = new VerificationController($models);
    }

    public function dispatch(array $server, array $query, array &$session, string $body = ''): Response
    {
        try {
            RequestValidator::local($server);
            $route = array_key_exists('api', $query) ? $query['api'] : 'dashboard';
            if (!is_string($route)) {
                throw new HttpError(400, 'Invalid dashboard route.');
            }
            $isPost = $route === 'run' && ($server['REQUEST_METHOD'] ?? '') === 'POST';
            RequestValidator::method($server, $isPost ? 'POST' : 'GET');
            $store = new RunStore($session);
            if ($isPost) {
                $options = RequestValidator::run($server, $body, $this->token($session));
                return Response::json($this->publicRun($store->save($this->verification->run($options))), 201);
            }

            return match ($route) {
                'dashboard' => $this->page($this->token($session)),
                'models' => Response::json(['models' => $this->models->entries()]),
                'model' => Response::json($this->verification->inspect(
                    RequestValidator::id($query['id'] ?? null, 'model identifier'),
                    $this->optionalId($query, 'focus'),
                )),
                'runs' => Response::json($store->summarize()),
                'fixture-evidence' => Response::json([
                    'evidence' => (new FixtureEvidence())->read(dirname(__DIR__, 2) . '/examples/runtime-evidence/corrected-controls.json'),
                ]),
                'run' => Response::json($this->publicRun($this->run($store, $query))),
                'graph' => Response::json($this->verification->graph(
                    $this->run($store, $query),
                    $this->optionalId($query, 'focus'),
                    $this->optionalId($query, 'property'),
                )),
                'report' => (new ReportController())->download($this->run($store, $query), $query['format'] ?? 'json'),
                default => throw new HttpError(404, 'The dashboard route does not exist.'),
            };
        } catch (HttpError $error) {
            return Response::json(['error' => $error->getMessage()], $error->status, $error->headers);
        } catch (Throwable) {
            return Response::json(['error' => 'The dashboard could not complete the request.'], 500);
        }
    }

    private function token(array &$session): string
    {
        return $session['dashboard_csrf'] ??= bin2hex(random_bytes(32));
    }

    private function optionalId(array $query, string $key): ?string
    {
        return array_key_exists($key, $query) ? RequestValidator::id($query[$key], "$key identifier") : null;
    }

    private function run(RunStore $store, array $query): array
    {
        return $store->get(RequestValidator::id($query['id'] ?? null, 'run identifier'));
    }

    private function publicRun(array $run): array
    {
        unset($run['graph_index'], $run['fingerprint'], $run['property_count'], $run['finding_categories']);
        return $run;
    }

    private function page(string $csrf): Response
    {
        ob_start();
        require __DIR__ . '/dashboard.php';
        return new Response((string) ob_get_clean());
    }
}
