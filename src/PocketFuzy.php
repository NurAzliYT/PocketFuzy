<?php

declare(strict_types=1);

namespace PocketFuzy {

	use Composer\InstalledVersions;
	use PocketFuzy\errorhandler\ErrorToExceptionHandler;
	use PocketFuzy\thread\ThreadManager;
	use PocketFuzy\utils\Filesystem;
	use PocketFuzy\utils\MainLogger;
	use PocketFuzy\utils\Process;
	use PocketFuzy\utils\ServerKiller;
	use PocketFuzy\utils\Terminal;
	use PocketFuzy\utils\Timezone;
	use PocketFuzy\wizard\SetupWizard;

	require_once __DIR__ . '/VersionInfo.php';

	const MIN_PHP_VERSION = "7.4.0";

	/**
	 * @param string $message
	 * @return void
	 */
	function critical_error($message){
		echo "[ERROR] $message" . PHP_EOL;
	}

	/*
	 * Startup code. Do not look at it, it may harm you.
	 * This is the only non-class based file on this project.
	 * Enjoy it as much as I did writing it. I don't want to do it again.
	 */

	/**
	 * @return string[]
	 */
	function check_platform_dependencies(){
		if(version_compare(MIN_PHP_VERSION, PHP_VERSION) > 0){
			//If PHP version isn't high enough, anything below might break, so don't bother checking it.
			return [
				"PHP >= " . MIN_PHP_VERSION . " is required, but you have PHP " . PHP_VERSION . "."
			];
		}

		$messages = [];

		if(PHP_INT_SIZE < 8){
			$messages[] = "32-bit systems/PHP are no longer supported. Please upgrade to a 64-bit system, or use a 64-bit PHP binary if this is a 64-bit system.";
		}

		if(php_sapi_name() !== "cli"){
			$messages[] = "Only PHP CLI is supported.";
		}

		$extensions = [
			"chunkutils2" => "PocketMine ChunkUtils v2",
			"curl" => "cURL",
			"crypto" => "php-crypto",
			"ctype" => "ctype",
			"date" => "Date",
			"ds" => "Data Structures",
			"gmp" => "GMP",
			"hash" => "Hash",
			"igbinary" => "igbinary",
			"json" => "JSON",
			"leveldb" => "LevelDB",
			"mbstring" => "Multibyte String",
			"morton" => "morton",
			"openssl" => "OpenSSL",
			"pcre" => "PCRE",
			"phar" => "Phar",
			"pthreads" => "pthreads",
			"reflection" => "Reflection",
			"sockets" => "Sockets",
			"spl" => "SPL",
			"yaml" => "YAML",
			"zip" => "Zip",
			"zlib" => "Zlib"
		];

		foreach($extensions as $ext => $name){
			if(!extension_loaded($ext)){
				$messages[] = "Unable to find the $name ($ext) extension.";
			}
		}

		if(extension_loaded("pthreads")){
			$pthreads_version = phpversion("pthreads");
			if(substr_count($pthreads_version, ".") < 2){
				$pthreads_version = "0.$pthreads_version";
			}
			if(version_compare($pthreads_version, "3.2.0") < 0){
				$messages[] = "pthreads >= 3.2.0 is required, while you have $pthreads_version.";
			}
		}

		if(extension_loaded("leveldb")){
			$leveldb_version = phpversion("leveldb");
			if(version_compare($leveldb_version, "0.2.1") < 0){
				$messages[] = "php-leveldb >= 0.2.1 is required, while you have $leveldb_version.";
			}
		}

		if(extension_loaded("pocketmine")){
			$messages[] = "The native PocketMine extension is no longer supported.";
		}

		return $messages;
	}

	/**
	 * @return void
	 */
	function emit_performance_warnings(\Logger $logger){
		if(extension_loaded("xdebug")){
			$logger->warning("Xdebug extension is enabled. This has a major impact on performance.");
		}
		if(((int) ini_get('zend.assertions')) !== -1){
			$logger->warning("Debugging assertions are enabled. This may degrade performance. To disable them, set `zend.assertions = -1` in php.ini.");
		}
		if(\Phar::running(true) === ""){
			$logger->warning("Non-packaged installation detected. This will degrade autoloading speed and make startup times longer.");
		}
	}

	/**
	 * @return void
	 */
	function set_ini_entries(){
		ini_set("allow_url_fopen", '1');
		ini_set("display_errors", '1');
		ini_set("display_startup_errors", '1');
		ini_set("default_charset", "utf-8");
		ini_set('assert.exception', '1');
	}

	/**
	 * @return void
	 */
	function server(){
		if(count($messages = check_platform_dependencies()) > 0){
			echo PHP_EOL;
			$binary = version_compare(PHP_VERSION, "5.4") >= 0 ? PHP_BINARY : "unknown";
			critical_error("Selected PHP binary ($binary) does not satisfy some requirements.");
			foreach($messages as $m){
				echo " - $m" . PHP_EOL;
			}
			critical_error("Please recompile PHP with the needed configuration, or refer to the installation instructions at http://pmmp.rtfd.io/en/rtfd/installation.html.");
			echo PHP_EOL;
			exit(1);
		}
		unset($messages);

		error_reporting(-1);
		set_ini_entries();

		$opts = getopt("", ["bootstrap:"]);
		if(isset($opts["bootstrap"])){
			$bootstrap = ($real = realpath($opts["bootstrap"])) !== false ? $real : $opts["bootstrap"];
		}else{
			$bootstrap = dirname(__FILE__, 2) . '/vendor/autoload.php';
		}

		if($bootstrap === false or !is_file($bootstrap)){
			critical_error("Composer autoloader not found at " . $bootstrap);
			critical_error("Please install/update Composer dependencies or use provided builds.");
			exit(1);
		}
		define('PocketFuzy\COMPOSER_AUTOLOADER_PATH', $bootstrap);
		require_once(\PocketFuzy\COMPOSER_AUTOLOADER_PATH);

		$composerGitHash = InstalledVersions::getReference('PocketFuzy/pocketmine-mp');
		if($composerGitHash !== null){
			//we can't verify dependency versions if we were installed without using git
			$currentGitHash = explode("-", VersionInfo::getGitHash())[0];
			if($currentGitHash !== $composerGitHash){
				critical_error("Composer dependencies and/or autoloader are out of sync.");
				critical_error("- Current revision is $currentGitHash");
				critical_error("- Composer dependencies were last synchronized for revision $composerGitHash");
				critical_error("Out-of-sync Composer dependencies may result in crashes and classes not being found.");
				critical_error("Please synchronize Composer dependencies before running the server.");
				exit(1);
			}
		}
		if(extension_loaded('parallel')){
			\parallel\bootstrap(\pocketmine\COMPOSER_AUTOLOADER_PATH);
		}

		ErrorToExceptionHandler::set();

		$opts = getopt("", ["data:", "plugins:", "no-wizard", "enable-ansi", "disable-ansi"]);

		$dataPath = isset($opts["data"]) ? $opts["data"] . DIRECTORY_SEPARATOR : realpath(getcwd()) . DIRECTORY_SEPARATOR;
		$pluginPath = isset($opts["plugins"]) ? $opts["plugins"] . DIRECTORY_SEPARATOR : realpath(getcwd()) . DIRECTORY_SEPARATOR . "plugins" . DIRECTORY_SEPARATOR;
		Filesystem::addCleanedPath($pluginPath, Filesystem::CLEAN_PATH_PLUGINS_PREFIX);

		if(!file_exists($dataPath)){
			mkdir($dataPath, 0777, true);
		}

		$lockFilePath = $dataPath . '/server.lock';
		if(($pid = Filesystem::createLockFile($lockFilePath)) !== null){
			critical_error("Another " . VersionInfo::NAME . " instance (PID $pid) is already using this folder (" . realpath($dataPath) . ").");
			critical_error("Please stop the other server first before running a new one.");
			exit(1);
		}

		//Logger has a dependency on timezone
		Timezone::init();

		if(isset($opts["enable-ansi"])){
			Terminal::init(true);
		}elseif(isset($opts["disable-ansi"])){
			Terminal::init(false);
		}else{
			Terminal::init();
		}

		$logger = new MainLogger($dataPath . "server.log", Terminal::hasFormattingCodes(), "Server", new \DateTimeZone(Timezone::get()));
		\GlobalLogger::set($logger);

		emit_performance_warnings($logger);

		$exitCode = 0;
		do{
			if(!file_exists($dataPath . "server.properties") and !isset($opts["no-wizard"])){
				$installer = new SetupWizard($dataPath);
				if(!$installer->run()){
					$exitCode = -1;
					break;
				}
			}

			/*
			 * We now use the Composer autoloader, but this autoloader is still for loading plugins.
			 */
			$autoloader = new \BaseClassLoader();
			$autoloader->register(false);

			new Server($autoloader, $logger, $dataPath, $pluginPath);

			$logger->info("Stopping other threads");

			$killer = new ServerKiller(8);
			$killer->start(PTHREADS_INHERIT_NONE);
			usleep(10000); //Fixes ServerKiller not being able to start on single-core machines

			if(ThreadManager::getInstance()->stopAll() > 0){
				$logger->debug("Some threads could not be stopped, performing a force-kill");
				Process::kill(Process::pid());
			}
		}while(false);

		$logger->shutdownLogWriterThread();

		echo Terminal::$FORMAT_RESET . PHP_EOL;

		Filesystem::releaseLockFile($lockFilePath);

		exit($exitCode);
	}

	\pocketmine\server();
}
