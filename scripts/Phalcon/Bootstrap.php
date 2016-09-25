<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Developer Tools                                                |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2016 Phalcon Team (http://www.phalconphp.com)       |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file docs/LICENSE.txt.                        |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  |          Serghei Iakovlev <serghei@phalconphp.com>                     |
  +------------------------------------------------------------------------+
*/

namespace Phalcon;

use Phalcon\Mvc\View;
use DirectoryIterator;
use Phalcon\Utils\FsUtils;
use Phalcon\Utils\DbUtils;
use Phalcon\Db\Adapter\Pdo;
use Phalcon\Utils\SystemInfo;
use Phalcon\Di\FactoryDefault;
use Phalcon\Error\ErrorHandler;
use Phalcon\Mvc\View\Engine\Php;
use Phalcon\Logger\Adapter\Stream;
use Phalcon\Mvc\Url as UrlResolver;
use Phalcon\Resources\AssetsResource;
use Phalcon\Mvc\View\NotFoundListener;
use Phalcon\Elements\Menu\SidebarMenu;
use Phalcon\Flash\Direct as FlashDirect;
use Phalcon\Access\Policy\Ip as IpPolicy;
use Phalcon\Flash\Session as FlashSession;
use Phalcon\Mvc\Dispatcher as MvcDispatcher;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Assets\Manager as AssetsManager;
use Phalcon\Access\Manager as AccessManager;
use Phalcon\Scanners\Config as ConfigScanner;
use Phalcon\Session\Adapter\Files as Session;
use Phalcon\Logger\Adapter\File as FileLogger;
use Phalcon\Mvc\Application as MvcApplication;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Application as AbstractApplication;
use Phalcon\Config\Exception as ConfigException;
use Phalcon\Cache\Frontend\None as FrontendNone;
use Phalcon\Cache\Backend\Memory as BackendCache;
use Phalcon\Cache\Frontend\Output as FrontOutput;
use Phalcon\Logger\Formatter\Line as LineFormatter;
use Phalcon\Mvc\Router\Annotations as AnnotationsRouter;
use Phalcon\Mvc\View\Engine\Volt\Extension\Php as PhpExt;
use Phalcon\Annotations\Adapter\Memory as AnnotationsMemory;
use Phalcon\Mvc\Dispatcher\ErrorHandler as DispatchErrorHandler;

/**
 * \Phalcon\Web\Tools\Library\Bootstrap
 *
 * @package Phalcon\Web\Tools\Library
 */
class Bootstrap
{
    use Configurable, Initializable;

    /**
     * Application instance.
     * @var AbstractApplication
     */
    private $app;

    /**
     * The services container.
     * @var FactoryDefault
     */
    private $di;

    /**
     * The path to the Phalcon Developers Tools.
     * @var string
     */
    private $ptoolsPath = '';

    /**
     * The allowed IP for access.
     * @var string
     */
    private $ptoolsIp = '';

    /**
     * The path where the project was created.
     * @var string
     */
    private $basePath = '';

    /**
     * The DevTools templates path.
     * @var string
     */
    private $templatesPath = '';

    /**
     * The current hostname.
     * @var string
     */
    private $hostName = 'Unknown';

    /**
     * The current application mode.
     * @var string
     */
    private $mode = 'web';

    /**
     * Configurable parameters
     * @var array
     */
    protected $configurable = [
        'ptools_path',
        'ptools_ip',
        'base_path',
        'host_name',
        'templates_path',
    ];

    /**
     * Parameters that can be set using constants
     * @var array
     */
    protected $defines = [
        'PTOOLSPATH',
        'PTOOLS_IP',
        'BASE_PATH',
        'HOSTNAME',
        'TEMPLATE_PATH',
    ];

    private $loaders = [
        'web' => [
            'eventsManager',
            'config',
            'logger',
            'cache',
            'volt',
            'view',
            'annotations',
            'router',
            'url',
            'tag',
            'dispatcher',
            'assets',
            'session',
            'flash',
            'database',
            'accessManager',
            'registry',
            'utils',
            'ui',
        ],
    ];

    /**
     * Bootstrap constructor.
     *
     * @param array|\Traversable $parameters
     */
    public function __construct($parameters = [])
    {
        $this->defines = array_combine($this->defines, $this->configurable);

        $this->initFromConstants();
        $this->setParameters($parameters);

        $this->di  = new FactoryDefault;
        $this->app = new MvcApplication;

        (new ErrorHandler)->register();

        foreach ($this->loaders[$this->mode] as $service) {
            $serviceName = ucfirst($service);
            $this->{'init' . $serviceName}();
        }

        $this->app->setEventsManager($this->di->getShared('eventsManager'));

        $this->di->setShared('application', $this->app);
        $this->app->setDI($this->di);

        Di::setDefault($this->di);
    }

    /**
     * Runs the Application.
     *
     * @return AbstractApplication|string
     */
    public function run()
    {
        if (PHP_SAPI == 'cli') {
            set_time_limit(0);
        }

        if (ENV_TESTING === APPLICATION_ENV) {
            return $this->app;
        }

        return $this->getOutput();
    }

    /**
     * Get application output.
     *
     * @return string
     */
    public function getOutput()
    {
        return $this->app->handle()->getContent();
    }

    /**
     * Sets the path to the Phalcon Developers Tools.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setPtoolsPath($path)
    {
        $this->ptoolsPath = rtrim($path, '\\/');

        return $this;
    }

    /**
     * Gets the path to the Phalcon Developers Tools.
     *
     * @return string
     */
    public function getPtoolsPath()
    {
        return $this->ptoolsPath;
    }

    /**
     * Sets the allowed IP for access.
     *
     * @param string $ip
     *
     * @return $this
     */
    public function setPtoolsIp($ip)
    {
        $this->ptoolsIp = trim($ip);

        return $this;
    }

    /**
     * Gets the allowed IP for access.
     *
     * @return string
     */
    public function getPtoolsIp()
    {
        return $this->ptoolsIp;
    }

    /**
     * Sets the path where the project was created.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setBasePath($path)
    {
        $this->basePath = rtrim($path, '\\/');

        return $this;
    }

    /**
     * Gets the path where the project was created.
     *
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * Sets the DevTools templates path.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setTemplatesPath($path)
    {
        $this->templatesPath = rtrim($path, '\\/');

        return $this;
    }

    /**
     * Gets the DevTools templates path.
     *
     * @return string
     */
    public function getTemplatesPath()
    {
        return $this->templatesPath;
    }

    /**
     * Sets the current application mode.
     *
     * @param string $mode
     *
     * @return $this
     */
    public function setMode($mode)
    {
        $mode = strtolower(trim($mode));

        if (isset($this->loaders[$mode])) {
            $mode = 'web';
        }

        $this->mode = $mode;

        return $this;
    }

    /**
     * Gets the current application mode.
     *
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * Sets the current hostname.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setHostName($name)
    {
        $this->hostName = trim($name);

        return $this;
    }

    /**
     * Gets the current application mode.
     *
     * @return string
     */
    public function getHostName()
    {
        return $this->hostName;
    }

    /**
     * Initialize the Application Events Manager.
     */
    protected function initEventsManager()
    {
        $this->di->setShared(
            'eventsManager',
            function () {
                $em = new EventsManager;
                $em->enablePriorities(true);

                return $em;
            }
        );
    }

    /**
     * Initialize the Application Config.
     */
    protected function initConfig()
    {
        $basePath = $this->basePath;
        $this->di->setShared(
            'config',
            function () use($basePath) {
                /** @var DiInterface $this */
                $scanner = new ConfigScanner($basePath);

                $config = $scanner->scan('config');

                if (null === $config) {
                    throw new ConfigException(
                        sprintf(
                            "Configuration file couldn't be loaded! Scanned paths: %s",
                            join(', ', $scanner->getConfigPaths())
                        )
                    );
                }

                if (!$config instanceof Config) {
                    $type = gettype($config);

                    if ($type == 'boolean') {
                        $type .= ($type ? ' (true)' : ' (false)');
                    } elseif (is_object($type)) {
                        $type = get_class($type);
                    }

                    throw new ConfigException(
                        sprintf(
                            'Unable to read config file. Config must be either an array or %s instance. Got %s',
                            Config::class,
                            $type
                        )
                    );
                }

                if (ENV_PRODUCTION !== APPLICATION_ENV) {
                    $override = $scanner->scan(APPLICATION_ENV);
                    if ($override instanceof Config) {
                        $config->merge($override);
                    }
                }

                return $config;
            }
        );
    }

    /**
     * Initialize the Logger.
     */
    protected function initLogger()
    {
        $hostName = $this->hostName;
        $basePath = $this->basePath;

        $this->di->setShared(
            'logger',
            function () use ($hostName, $basePath) {
                $logLevel = Logger::ERROR;
                if (ENV_DEVELOPMENT === APPLICATION_ENV) {
                    $logLevel = Logger::DEBUG;
                }

                $ptoolsPath = $basePath . DS . '.phalcon' . DS;
                if (is_dir($ptoolsPath) && is_writable($ptoolsPath)) {
                    $formatter = new LineFormatter("%date% {$hostName} php: [%type%] %message%", 'D j H:i:s');
                    $logger    = new FileLogger($ptoolsPath . 'devtools.log');
                } else {
                    $formatter = new LineFormatter("[devtools@{$hostName}]: [%type%] %message%", 'D j H:i:s');
                    $logger    = new Stream('php://stderr');
                }

                $logger->setFormatter($formatter);
                $logger->setLogLevel($logLevel);

                return $logger;
            }
        );
    }

    /**
     * Initialize the Cache.
     *
     * The frontend must always be Phalcon\Cache\Frontend\Output and the service 'viewCache'
     * must be registered as always open (not shared) in the services container (DI).
     */
    protected function initCache()
    {
        $this->di->set(
            'viewCache',
            function () {
                return new BackendCache(new FrontOutput);
            }
        );

        $this->di->setShared(
            'modelsCache',
            function () {
                return new BackendCache(new FrontendNone);
            }
        );

        $this->di->setShared(
            'dataCache',
            function () {
                return new BackendCache(new FrontendNone);
            }
        );
    }

    /**
     * Initialize the Volt Template Engine.
     */
    protected function initVolt()
    {
        $basePath = $this->basePath;
        $ptoolsPath = $this->ptoolsPath;

        $this->di->setShared(
            'volt',
            function ($view, $di) use ($basePath, $ptoolsPath) {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 * @var Config $voltConfig
                 */

                $volt = new VoltEngine($view, $di);
                $config = $this->getShared('config');

                $appCacheDir = $config->get('application', new Config)->get('cacheDir');
                $defaultCacheDir = sys_get_temp_dir() . DS . 'phalcon' . DS . 'volt';

                $voltConfig = null;
                if ($config->offsetExists('volt')) {
                    $voltConfig = $config->get('volt');
                } elseif ($config->offsetExists('view')) {
                    $voltConfig = $config->get('view');
                }

                if (!$voltConfig instanceof Config) {
                    $voltConfig = new Config([
                        'compiledExt'  => '.php',
                        'separator'    => '_',
                        'cacheDir'     => $appCacheDir ?: $defaultCacheDir,
                        'forceCompile' => ENV_DEVELOPMENT === APPLICATION_ENV,
                    ]);
                }

                $compiledPath = function ($templatePath) use (
                    $voltConfig,
                    $basePath,
                    $ptoolsPath,
                    $appCacheDir,
                    $defaultCacheDir
                ) {
                    /**
                     * @var DiInterface $this
                     * @var Config $voltConfig
                     */

                    if (0 === strpos($templatePath, $basePath)) {
                        $templatePath = substr($templatePath, strlen($basePath));
                    } elseif (0 === strpos($templatePath, $ptoolsPath . DS . 'scripts')) {
                        $templatePath = substr($templatePath, strlen($ptoolsPath . DS . 'scripts'));
                    }

                    $filename = str_replace(['\\', '/'], $voltConfig->get('separator', '_'), trim($templatePath, '\\/'));
                    $filename = basename($filename, '.volt') . $voltConfig->get('compiledExt', '.php');
                    $cacheDir = $voltConfig->get('cacheDir', $appCacheDir);

                    if (!$cacheDir || !is_dir($cacheDir) || !is_writable($cacheDir)) {
                        $this->getShared('logger')->warning(
                            'Unable to initialize Volt cache dir: {cache}. Used temp path: {default}',
                            [
                                'cache'   => $cacheDir,
                                'default' => $defaultCacheDir
                            ]
                        );

                        $cacheDir = $defaultCacheDir;
                        mkdir($cacheDir, 0777, true);
                    }

                    return rtrim($cacheDir, '\\/') . DS . $filename;
                };

                $options = [
                    'compiledPath'  => $voltConfig->get('compiledPath', $compiledPath),
                    'compileAlways' => ENV_DEVELOPMENT === APPLICATION_ENV || boolval($voltConfig->get('forceCompile')),
                ];

                $volt->setOptions($options);
                $volt->getCompiler()->addExtension(new PhpExt);

                return $volt;
            }
        );
    }

    /**
     * Initialize the View.
     */
    protected function initView()
    {
        $this->di->setShared(
            'view',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Registry $registry
                 */

                $view = new View;
                $registry = $this->getShared('registry');

                $view->registerEngines(
                    [
                        '.volt'  => $this->getShared('volt', [$view, $this]),
                        '.phtml' => Php::class
                    ]
                );

                $view->setViewsDir($registry->offsetGet('directories')->webToolsViews . DS)
                    ->setLayoutsDir('layouts' . DS)
                    ->setRenderLevel(View::LEVEL_AFTER_TEMPLATE);

                $em = $this->getShared('eventsManager');
                $em->attach('view', new NotFoundListener);

                $view->setEventsManager($em);

                return $view;
            }
        );
    }

    /**
     * Initialize the Annotations.
     */
    protected function initAnnotations()
    {
        $this->di->setShared(
            'annotations',
            function () {
                return new AnnotationsMemory;
            }
        );
    }

    /**
     * Initialize the Router.
     */
    protected function initRouter()
    {
        $ptoolsPath = $this->ptoolsPath;

        $this->di->setShared(
            'router',
            function () use ($ptoolsPath) {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                $router = new AnnotationsRouter(false);
                $router->removeExtraSlashes(true);

                // @todo Use Path::normalize()
                $controllersDir = $ptoolsPath . DS . str_replace('/', DS, 'scripts/Phalcon/Web/Tools/Controllers');
                $dir = new DirectoryIterator($controllersDir);

                $resources = [];

                foreach ($dir as $fileInfo) {
                    if ($fileInfo->isDot() || false === strpos($fileInfo->getBasename(), 'Controller.php')) {
                        continue;
                    }

                    $controller = $fileInfo->getBasename('Controller.php');
                    $resources[] = $controller;
                }

                foreach ($resources as $controller) {
                    $router->addResource($controller);
                }

                $router->setEventsManager($em);
                $router->setDefaultAction('index');
                $router->setDefaultController('index');
                $router->setDefaultNamespace('WebTools\Controllers');
                $router->notFound(['controller' => 'error', 'action' => 'route404']);

                return $router;
            }
        );
    }

    /**
     * Initialize the Url service.
     */
    protected function initUrl()
    {
        $this->di->setShared(
            'url',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 */
                $config = $this->getShared('config');

                $url = new UrlResolver;

                if ($config->get('application', new Config)->offsetExists('baseUri')) {
                    $baseUri = $config->get('application', new Config)->get('baseUri');
                } elseif ($config->offsetExists('baseUri')) {
                    $baseUri = $config->get('baseUri');
                } else {
                    // @todo Log notice here
                    $baseUri = '/';
                }

                if ($config->get('application', new Config)->offsetExists('staticUri')) {
                    $staticUri = $config->get('application', new Config)->get('staticUri');
                } elseif ($config->offsetExists('staticUri')) {
                    $staticUri = $config->get('staticUri');
                } else {
                    // @todo Log notice here
                    $staticUri = '/';
                }

                $url->setBaseUri($baseUri);
                $url->setStaticBaseUri($staticUri);

                return $url;
            }
        );
    }

    /**
     * Initialize the Tag Service.
     */
    protected function initTag()
    {
        $this->di->setShared(
            'tag',
            function () {
                $tag = new Tag;

                $tag->setDocType(Tag::HTML5);
                $tag->setTitleSeparator(' :: ');
                $tag->setTitle('Phalcon WebTools');

                return $tag;
            }
        );
    }

    /**
     * Initialize the Dispatcher.
     */
    protected function initDispatcher()
    {
        $this->di->setShared(
            'dispatcher',
            function () {
                /** @var DiInterface $this */
                $em = $this->get('eventsManager');

                $dispatcher = new MvcDispatcher;
                $dispatcher->setDefaultNamespace('WebTools\Controllers');

                $em->attach('dispatch', $this->getShared('access'), 1000);
                $em->attach('dispatch:beforeException', new DispatchErrorHandler, 999);

                $dispatcher->setEventsManager($em);

                return $dispatcher;
            }
        );
    }

    /**
     * Initialize the Assets Manager.
     */
    protected function initAssets()
    {
        $this->di->setShared(
            'assets',
            function () {
                return new AssetsManager;
            }
        );
    }

    /**
     * Initialize the Session Service.
     */
    protected function initSession()
    {
        $this->di->setShared(
            'session',
            function () {
                $session = new Session;
                $session->start();

                return $session;
            }
        );
    }

    /**
     * Initialize the Flash Service.
     */
    protected function initFlash()
    {
        $this->di->setShared(
            'flash',
            function () {
                $flash = new FlashDirect(
                    [
                        'error'   => 'alert alert-danger fade in',
                        'success' => 'alert alert-success fade in',
                        'notice'  => 'alert alert-info fade in',
                        'warning' => 'alert alert-warning fade in',
                    ]
                );

                $flash->setAutoescape(false);

                return $flash;
            }
        );

        $this->di->setShared(
            'flashSession',
            function () {
                $flash = new FlashSession(
                    [
                        'error'   => 'alert alert-danger fade in',
                        'success' => 'alert alert-success fade in',
                        'notice'  => 'alert alert-info fade in',
                        'warning' => 'alert alert-warning fade in',
                    ]
                );


                $flash->setAutoescape(false);

                return $flash;
            }
        );
    }

    /**
     * Initialize the Database connection.
     */
    protected function initDatabase()
    {
        $this->di->setShared(
            'db',
            function () {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                if ($this->getShared('config')->offsetExists('database')) {
                    $config = $this->getShared('config')->get('database')->toArray();
                } else {
                    $dbname = sys_get_temp_dir() . DS . 'phalcon.sqlite';
                    $this->getShared('logger')->warning(
                        'Unable to initialize "db" service. Used Sqlite adapter at path: {path}', ['path' => $dbname]
                    );

                    $config = [
                        'adapter' => 'Sqlite',
                        'dbname'  => $dbname,
                    ];
                }

                $adapter = 'Phalcon\Db\Adapter\Pdo\\' . $config['adapter'];
                unset($config['adapter']);

                /** @var Pdo $connection */
                $connection = new $adapter($config);

                $connection->setEventsManager($em);

                return $connection;
            }
        );
    }

    /**
     * Initialize the Access Manager.
     */
    protected function initAccessManager()
    {
        $ptoolsIp = $this->ptoolsIp;

        $this->di->setShared(
            'access',
            function () use ($ptoolsIp) {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                $policy = new IpPolicy($ptoolsIp);

                $manager = new AccessManager($policy);
                $manager->setEventsManager($em);

                return $manager;
            }
        );
    }

    /**
     * Initialize the global registry.
     */
    protected function initRegistry()
    {
        $basePath   = $this->basePath;
        $ptoolsPath = $this->ptoolsPath;
        $templatesPath = $this->templatesPath;

        $this->di->setShared(
            'registry',
            function () use ($basePath, $ptoolsPath, $templatesPath) {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 * @var FsUtils $fs
                 */
                $registry = new Registry;

                $config  = $this->getShared('config');
                $fs      = $this->getShared('fs');

                $basePath = $fs->normalize(rtrim($basePath, '\\/'));
                $ptoolsPath = $fs->normalize(rtrim($ptoolsPath, '\\/'));
                $templatesPath = $fs->normalize(rtrim($templatesPath, '\\/'));

                $requiredDirectories = [
                    'modelsDir',
                    'controllersDir',
                    'migrationsDir',
                ];

                $directories = [
                    'modelsDir'      => null,
                    'controllersDir' => null,
                    'migrationsDir'  => null,
                    'basePath'       => $basePath,
                    'ptoolsPath'     => $ptoolsPath,
                    'templatesPath'  => $templatesPath,
                    'webToolsViews'  => $fs->normalize($ptoolsPath . '/scripts/Phalcon/Web/Tools/Views'),
                    'resourcesDir'   => $fs->normalize($ptoolsPath . '/resources'),
                    'elementsDir'    => $fs->normalize($ptoolsPath . '/resources/elements')
                ];

                if (($application = $config->get('application')) instanceof Config) {
                    foreach ($requiredDirectories as $name) {
                        if ($possiblePath = $application->get($name)) {
                            if (!$fs->isAbsolute($possiblePath)) {
                                $possiblePath = $basePath . DS . $possiblePath;
                            }

                            $possiblePath = $fs->normalize($possiblePath);
                            if (is_readable($possiblePath) && is_dir($possiblePath)) {
                                $directories[$name] = $possiblePath;
                            }
                        }
                    }
                }

                $registry->offsetSet('directories', (object) $directories);

                return $registry;
            }
        );
    }

    /**
     * Initialize utilities.
     */
    protected function initUtils()
    {
        $this->di->setShared(
            'fs',
            function () {
                return new FsUtils;
            }
        );

        $this->di->setShared(
            'info',
            function () {
                return new SystemInfo;
            }
        );

        $this->di->setShared(
            'dbUtils',
            function () {
                return new DbUtils;
            }
        );

        $this->di->setShared(
            'resource',
            function () {
                return new AssetsResource;
            }
        );
    }

    /**
     * Initialize User Interface components (mostly HTML elements).
     */
    protected function initUi()
    {
        $this->di->setShared(
            'sidebar',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Registry $registry
                 */
                $registry = $this->getShared('registry');
                $menuItems = $registry->offsetGet('directories')->elementsDir . DS . 'sidebar-menu.php';

                /** @noinspection PhpIncludeInspection */
                $menu = new SidebarMenu(include $menuItems);

                $menu->setDI($this);

                return $menu;
            }
        );
    }
}