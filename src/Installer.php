<?php
namespace paw\plugin\installer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use paw\plugin\installer\InvalidPluginException;

class Installer extends LibraryInstaller
{
    const PLUGINS_FILE = 'pawcode/plugins.php';

    public function supports($packageType)
    {
        return $packageType == 'paw-plugin';
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);
        try {
            $this->addPlugin($package);
        } catch (InvalidPluginException $ex) {
            parent::uninstall($repo, $package);
            throw $ex;
        }
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);
        $initialPlugin = $this->removePlugin($initial);
        try {
            $this->addPlugin($target);
        } catch (InvalidPluginException $ex) {
            parent::update($repo, $target, $initial);
            if ($initialPlugin !== null) {
                $this->registerPlugin($initial->getName(), $initialPlugin);
            }

            throw $ex;
        }
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);
        $this->removePlugin($package);
    }

    protected function registerPlugin($name, array $plugin)
    {
        $plugins = $this->loadPlugins();
        $plugins[$name] = $plugin;
        $this->savePlugins($plugins);
    }

    protected function unregisterPlugin($name)
    {
        $plugins = $this->loadPlugins();
        if (!isset($plugins[$name])) {
            return null;
        }
        $plugin = $plugins[$name];
        unset($plugins[$name]);
        $this->savePlugins($plugins);
        return $plugin;
    }

    protected function addPlugin(PackageInterface $package)
    {
        $extra = $package->getExtra();
        $prettyName = $package->getPrettyName();

        // default info
        list(
            $defaultNamespace,
            $defaultBasePath,
            $defaultClass,
            $defaultClassFile,
            $defaultHandle
        ) = $this->getDefaultPluginInfo($package);

        // Plugin class
        $class = isset($extra['class']) ? $extra['class'] : $defaultClass;
        $basePath = isset($extra['basePath']) ? $extra['basePath'] : $defaultBasePath;
        $handle = isset($extra['handle']) ? $extra['handle'] : $defaultHandle;
        $aliases = $this->generateDefaultAliases($package, $class, $basePath);

        if ($class === null) {
            throw new InvalidPluginException($package, 'Unable to determine the Plugin class');
        }

        if ($basePath === null) {
            throw new InvalidPluginException($package, 'Unable to determine the base path');
        }

        if (!isset($handle) || !preg_match('/^[a-zA-Z][\w\-]*$/', $handle)) {
            throw new InvalidPluginException($package, 'Invalid or missing plugin handle');
        }

        $plugin = [
            'class' => $class,
            'basePath' => $basePath,
            'handle' => $handle,
        ];

        if ($aliases) {
            $plugin['aliase'] = $aliases;
        }

        if (strpos($prettyName, '/') !== false) {
            list($vendor, $name) = explode('/', $prettyName);
        } else {
            $vendor = null;
            $name = $prettyName;
        }

        if (isset($extra['name'])) {
            $plugin['name'] = $extra['name'];
        } else {
            $plugin['name'] = $name;
        }

        if (isset($extra['version'])) {
            $plugin['version'] = $extra['version'];
        } else {
            $plugin['version'] = $package->getPrettyVersion();
        }

        if (isset($extra['description'])) {
            $plugin['description'] = $extra['description'];
        } else if ($package instanceof CompletePackageInterface && ($description = $package->getDescription())) {
            $plugin['description'] = $description;
        }

        if (isset($extra['author'])) {
            $plugin['author'] = $extra['author'];
        } else if ($authorName = $this->getAuthorProperty($package, 'name')) {
            $plugin['author'] = $authorName;
        } else if ($vendor !== null) {
            $plugin['author'] = $vendor;
        }

        $this->registerPlugin($package->getName(), $plugin);
    }

    protected function savePlugins(array $plugins)
    {
        $file = $this->vendorDir . '/' . static::PLUGINS_FILE;
        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($plugins, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    protected function removePlugin(PackageInterface $package)
    {
        return $this->unregisterPlugin($package->getName());
    }

    protected function loadPlugins()
    {
        $file = $this->vendorDir . '/' . static::PLUGINS_FILE;
        if (!is_file($file)) {
            return [];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $plugins = require $file;
        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $n = strlen($vendorDir);
        foreach ($plugins as &$plugin) {
            // basePath
            if (isset($plugin['basePath'])) {
                $path = str_replace('\\', '/', $plugin['basePath']);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $plugin['basePath'] = '<vendor-dir>' . substr($path, $n);
                }
            }
            // aliases
            if (isset($plugin['aliases'])) {
                foreach ($plugin['aliases'] as $alias => $path) {
                    $path = str_replace('\\', '/', $path);
                    if (strpos($path . '/', $vendorDir . '/') === 0) {
                        $plugin['aliases'][$alias] = '<vendor-dir>' . substr($path, $n);
                    }
                }
            }
        }
        return $plugins;
    }

    protected function getAuthorProperty(PackageInterface $package, $property)
    {
        if (!$package instanceof CompletePackageInterface) {
            return null;
        }
        $authors = $package->getAuthors();
        if (empty($authors)) {
            return null;
        }
        $firstAuthor = reset($authors);
        if (!isset($firstAuthor[$property])) {
            return null;
        }
        return $firstAuthor[$property];
    }

    protected function generateDefaultAliases(PackageInterface $package, &$class, &$basePath)
    {
        $autoload = $package->getAutoload();
        if (empty($autoload['psr-4'])) {
            return null;
        }
        $fs = new Filesystem();
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $aliases = [];
        foreach ($autoload['psr-4'] as $namespace => $path) {
            if (is_array($path)) {
                continue;
            }

            if (!$fs->isAbsolutePath($path)) {
                $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
            }
            $path = $fs->normalizePath($path);
            $alias = '@' . str_replace('\\', '/', trim($namespace, '\\'));
            if (strpos($path . '/', $vendorDir . '/') === 0) {
                $aliases[$alias] = '<vendor-dir>' . substr($path, strlen($vendorDir));
            } else {
                $aliases[$alias] = $path;
            }
            if ($class === null && file_exists($path . '/Plugin.php')) {
                $class = $namespace . 'Plugin';
            }
            if ($basePath === null && $class !== null) {
                $n = strlen($namespace);
                if (strncmp($namespace, $class, $n) === 0) {
                    $testClassPath = $path . '/' . str_replace('\\', '/', substr($class, $n)) . '.php';
                    if (file_exists($testClassPath)) {
                        $basePath = dirname($testClassPath);
                        // If the base path starts with the vendor dir path, swap with <vendor-dir>
                        if (strpos($basePath . '/', $vendorDir . '/') === 0) {
                            $basePath = '<vendor-dir>' . substr($basePath, strlen($vendorDir));
                        }
                    }
                }
            }
        }
        return $aliases;
    }

    protected function getPsr4(PackageInterface $package)
    {
        $psr4 = [];
        $autoload = $package->getAutoload();
        $fs = new Filesystem();
        $vendorDir = $fs->normalizePath($this->vendorDir);

        if (empty($autoload['psr-4'])) {
            return null;
        }
        foreach ($autoload['psr-4'] as $namespace => $path) {
            if (is_array($path)) {
                continue;
            }

            if (!$fs->isAbsolutePath($path)) {
                $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
            }
            $path = $fs->normalizePath($path);
            $psr4[$namespace] = $path;
        }
        return $psr4;
    }

    protected function getDefaultPluginInfo(PackageInterface $package)
    {
        $namespace = null;
        $basePath = null;
        $class = null;
        $classFile = null;
        $handle = null;

        $psr4 = $this->getPsr4($package);
        if ($psr4) {
            $firstpsr4 = array_shift($psr4);
            list($namespace, $basePath) = $firstpsr4;

            if (file_exists($basePath . '/Plugin.php')) {
                $class = $namespace . 'Plugin';
                $classFile = $basePath . '/Plugin.php';
            }

            $handle = str_replace('/', '-', $package->getPrettyName());
        }
        return [$namespace, $basePath, $class, $classFile, $handle];
    }
}
