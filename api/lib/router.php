<?php
/**
 * MAM Academy — Router
 * ─────────────────────────────────────────────────────────────
 * A small, deliberate router with method dispatch, route groups,
 * shared middleware, parameterized paths, and proper 405/404
 * handling. No dependencies. ~100 lines.
 *
 * Usage:
 *   $r = new MamRouter();
 *   $r->get('/health', $handler);
 *   $r->group('/auth', function ($r) {
 *       $r->post('/login', $loginHandler, [mw_csrf(), mw_rate_limit('login', 8, 600)]);
 *   });
 *   $r->dispatch($method, $path);
 *
 * Handlers receive a single array of named parameters captured
 * from the path pattern (e.g. /users/:id ⇒ ['id' => '42']).
 *
 * Middleware are callables of shape: fn(callable $next, array $params): void
 * They MUST call $next() to continue the chain, or short-circuit
 * (e.g. by calling mam_fail()) to refuse the request.
 */
declare(strict_types=1);

class MamRouter
{
    /** @var array<int, array{method:string,pattern:string,path:string,handler:callable,middleware:array<int,callable>}> */
    private array $routes = [];

    /** @var array<int,callable> middleware accumulated by enclosing group() calls */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';

    /* ─── Registration ─── */

    public function get   (string $p, callable $h, array $mw = []): self { return $this->add('GET',    $p, $h, $mw); }
    public function post  (string $p, callable $h, array $mw = []): self { return $this->add('POST',   $p, $h, $mw); }
    public function put   (string $p, callable $h, array $mw = []): self { return $this->add('PUT',    $p, $h, $mw); }
    public function patch (string $p, callable $h, array $mw = []): self { return $this->add('PATCH',  $p, $h, $mw); }
    public function delete(string $p, callable $h, array $mw = []): self { return $this->add('DELETE', $p, $h, $mw); }
    public function any   (string $p, callable $h, array $mw = []): self { return $this->add('*',      $p, $h, $mw); }

    public function group(string $prefix, callable $build, array $middleware = []): self
    {
        $prevPrefix = $this->groupPrefix;
        $prevMW     = $this->groupMiddleware;
        $this->groupPrefix     = $prevPrefix . $prefix;
        $this->groupMiddleware = array_merge($prevMW, $middleware);
        try {
            $build($this);
        } finally {
            $this->groupPrefix     = $prevPrefix;
            $this->groupMiddleware = $prevMW;
        }
        return $this;
    }

    private function add(string $method, string $path, callable $handler, array $middleware): self
    {
        $full = $this->groupPrefix . $path;
        if ($full === '' || $full === '/') $full = '/';
        // :param ⇒ named capture; segments cannot contain '/'
        $regex = preg_replace('#:(\w+)#', '(?<$1>[^/]+)', $full);
        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $full,
            'pattern'    => '#^' . $regex . '$#',
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
        return $this;
    }

    /* ─── Dispatch ─── */

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $allowedMethods = [];

        foreach ($this->routes as $r) {
            if (!preg_match($r['pattern'], $path, $m)) continue;
            if ($r['method'] !== '*' && $r['method'] !== $method) {
                $allowedMethods[$r['method']] = true;
                continue;
            }
            // Collect named captures only
            $params = [];
            foreach ($m as $k => $v) if (is_string($k)) $params[$k] = $v;

            // Compose middleware chain from outside-in
            $chain = function () use ($r, $params) { ($r['handler'])($params); };
            foreach (array_reverse($r['middleware']) as $mw) {
                $next  = $chain;
                $chain = function () use ($mw, $next, $params) { $mw($next, $params); };
            }
            $chain();
            return;
        }

        if (!empty($allowedMethods)) {
            $list = array_keys($allowedMethods);
            header('Allow: ' . implode(', ', $list));
            mam_fail('method_not_allowed', 405, ['allowed' => $list]);
        }
        mam_fail('route_not_found', 404, ['path' => $path]);
    }

    /* ─── Introspection (for debugging / docs) ─── */

    public function routes(): array
    {
        return array_map(function ($r) {
            return ['method' => $r['method'], 'path' => $r['path']];
        }, $this->routes);
    }
}

/* ─── Middleware factories ───
   Small helpers so route registration reads like a contract. */

function mw_csrf(): callable {
    return function (callable $next) { mam_csrf_require(); $next(); };
}
function mw_rate_limit(string $bucket, int $limit, int $windowSec): callable {
    return function (callable $next) use ($bucket, $limit, $windowSec) {
        mam_rate_limit($bucket, mam_client_ip(), $limit, $windowSec);
        $next();
    };
}
function mw_role(string ...$roles): callable {
    return function (callable $next) use ($roles) {
        mam_require_role(...$roles);
        $next();
    };
}
function mw_admin(): callable {
    return function (callable $next) { mam_require_admin(); $next(); };
}
/** Admin guard that also requires a capability (RBAC tier). Super passes all;
 *  manager passes operational caps only; Basic-Auth break-glass is treated as super. */
function mw_admin_cap(string $cap): callable {
    return function (callable $next) use ($cap) { mam_require_admin_cap($cap); $next(); };
}
function mw_user(): callable {
    return function (callable $next) { mam_require_user();  $next(); };
}
