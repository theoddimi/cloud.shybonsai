<?php

namespace Codexdelta\App;

use Codexdelta\Libs\Http\CdxRequest;
use Codexdelta\Libs\Router\Router;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class App
{
    protected $routes;
    protected Router $router;

    protected Environment $twig;

    protected static $app;
    protected function __construct(string $routesPath, Environment $twig)
    {

        if (realpath($routesPath) !== false) {
            $this->routes = require $routesPath;
            $this->router = Router::singleton();
            call_user_func($this->routes);
        }

        $this->twig = $twig;
    }

    public static function getInstance(string $routesPath, Environment $twig)
    {
        if (is_null(static::$app)) {
            static::$app = new static($routesPath, $twig);
        }

        return static::$app;
    }


    /**
     * @param CdxRequest $request
     * @return Response
     */
    public function handle(CdxRequest $request): Response
    {
        return $this->router->resolve($request);
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }

    public static function get()
    {
        if (static::$app instanceof App) {
            return static::$app;
        }

        throw new \Exception('App has not been initialized');
    }
}