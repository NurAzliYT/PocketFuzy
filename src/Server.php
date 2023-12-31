<?php

declare(strict_types=1);

/**
 * PocketFuzy is the Minecraft: PE multiplayer server software
 * Homepage: http://www.PocketFuzy.net/
 */
namespace PocketFuzy;

use PocketFuzy\command\Command;
use PocketFuzy\command\CommandReader;
use PocketFuzy\command\CommandSender;
use PocketFuzy\command\ConsoleCommandSender;
use PocketFuzy\command\SimpleCommandMap;
use PocketFuzy\crafting\CraftingManager;
use PocketFuzy\crafting\CraftingManagerFromDataHelper;
use PocketFuzy\event\HandlerListManager;
use PocketFuzy\event\player\PlayerCreationEvent;
use PocketFuzy\event\player\PlayerDataSaveEvent;
use PocketFuzy\event\server\CommandEvent;
use PocketFuzy\event\server\DataPacketSendEvent;
use PocketFuzy\event\server\QueryRegenerateEvent;
use PocketFuzy\lang\Language;
use PocketFuzy\lang\LanguageNotFoundException;
use PocketFuzy\lang\TranslationContainer;
use PocketFuzy\nbt\BigEndianNbtSerializer;
use PocketFuzy\nbt\NbtDataException;
use PocketFuzy\nbt\tag\CompoundTag;
use PocketFuzy\nbt\TreeRoot;
use PocketFuzy\network\mcpe\compression\CompressBatchPromise;
use PocketFuzy\network\mcpe\compression\CompressBatchTask;
use PocketFuzy\network\mcpe\compression\Compressor;
use PocketFuzy\network\mcpe\compression\ZlibCompressor;
use PocketFuzy\network\mcpe\encryption\EncryptionContext;
use PocketFuzy\network\mcpe\NetworkSession;
use PocketFuzy\network\mcpe\PacketBroadcaster;
use PocketFuzy\network\mcpe\protocol\ClientboundPacket;
use PocketFuzy\network\mcpe\protocol\ProtocolInfo;
use PocketFuzy\network\mcpe\protocol\serializer\PacketBatch;
use PocketFuzy\network\mcpe\raklib\RakLibInterface;
use PocketFuzy\network\Network;
use PocketFuzy\network\query\DedicatedQueryNetworkInterface;
use PocketFuzy\network\query\QueryHandler;
use PocketFuzy\network\query\QueryInfo;
use PocketFuzy\network\upnp\UPnP;
use PocketFuzy\permission\BanList;
use PocketFuzy\permission\DefaultPermissions;
use PocketFuzy\player\GameMode;
use PocketFuzy\player\OfflinePlayer;
use PocketFuzy\player\Player;
use PocketFuzy\player\PlayerInfo;
use PocketFuzy\plugin\ApiMap;
use PocketFuzy\plugin\PharPluginLoader;
use PocketFuzy\plugin\Plugin;
use PocketFuzy\plugin\PluginEnableOrder;
use PocketFuzy\plugin\PluginGraylist;
use PocketFuzy\plugin\PluginManager;
use PocketFuzy\plugin\PluginOwned;
use PocketFuzy\plugin\ScriptPluginLoader;
use PocketFuzy\resourcepacks\ResourcePackManager;
use PocketFuzy\scheduler\AsyncPool;
use PocketFuzy\snooze\SleeperHandler;
use PocketFuzy\snooze\SleeperNotifier;
use PocketFuzy\stats\SendUsageTask;
use PocketFuzy\timings\Timings;
use PocketFuzy\timings\TimingsHandler;
use PocketFuzy\updater\AutoUpdater;
use PocketFuzy\utils\Config;
use PocketFuzy\utils\Filesystem;
use PocketFuzy\utils\Internet;
use PocketFuzy\utils\MainLogger;
use PocketFuzy\utils\Process;
use PocketFuzy\utils\Terminal;
use PocketFuzy\utils\TextFormat;
use PocketFuzy\utils\Utils;
use PocketFuzy\uuid\UUID;
use PocketFuzy\world\format\io\WorldProviderManager;
use PocketFuzy\world\format\io\WritableWorldProvider;
use PocketFuzy\world\generator\Generator;
use PocketFuzy\world\generator\GeneratorManager;
use PocketFuzy\world\generator\normal\Normal;
use PocketFuzy\world\World;
use PocketFuzy\world\WorldManager;
use function array_shift;
use function array_sum;
use function base64_encode;
use function cli_set_process_title;
use function copy;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function get_class;
use function implode;
use function ini_set;
use function is_a;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function mkdir;
use function ob_end_flush;
use function preg_replace;
use function realpath;
use function register_shutdown_function;
use function rename;
use function round;
use function sleep;
use function spl_object_id;
use function sprintf;
use function str_repeat;
use function str_replace;
use function stripos;
use function strlen;
use function strrpos;
use function strtolower;
use function time;
use function touch;
use function trim;
use function yaml_parse;
use function zlib_decode;
use function zlib_encode;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
use const PHP_INT_MAX;
use const PTHREADS_INHERIT_NONE;
use const ZLIB_ENCODING_GZIP;

/**
 * The class that manages everything
 */
class Server{
	public const BROADCAST_CHANNEL_ADMINISTRATIVE = "PocketFuzy.broadcast.admin";
	public const BROADCAST_CHANNEL_USERS = "PocketFuzy.broadcast.user";

	/** @var Server|null */
	private static $instance = null;

	/** @var SleeperHandler */
	private $tickSleeper;

	/** @var BanList */
	private $banByName;

	/** @var BanList */
	private $banByIP;

	/** @var Config */
	private $operators;

	/** @var Config */
	private $whitelist;

	/** @var bool */
	private $isRunning = true;

	/** @var bool */
	private $hasStopped = false;

	/** @var PluginManager */
	private $pluginManager;

	/** @var ApiMap */
	private $apiMap;

	/** @var float */
	private $profilingTickRate = 20;

	/** @var AutoUpdater */
	private $updater;

	/** @var AsyncPool */
	private $asyncPool;

	/**
	 * Counts the ticks since the server start
	 *
	 * @var int
	 */
	private $tickCounter = 0;
	/** @var float */
	private $nextTick = 0;
	/** @var float[] */
	private $tickAverage = [20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20];
	/** @var float[] */
	private $useAverage = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
	/** @var float */
	private $currentTPS = 20;
	/** @var float */
	private $currentUse = 0;
	/** @var float */
	private $startTime;

	/** @var bool */
	private $doTitleTick = true;

	/** @var int */
	private $sendUsageTicker = 0;

	/** @var \AttachableThreadedLogger */
	private $logger;

	/** @var MemoryManager */
	private $memoryManager;

	/** @var CommandReader */
	private $console;

	/** @var SimpleCommandMap */
	private $commandMap;

	/** @var CraftingManager */
	private $craftingManager;

	/** @var ResourcePackManager */
	private $resourceManager;

	/** @var WorldManager */
	private $worldManager;

	/** @var int */
	private $maxPlayers;

	/** @var bool */
	private $onlineMode = true;

	/** @var Network */
	private $network;
	/** @var bool */
	private $networkCompressionAsync = true;

	/** @var Language */
	private $language;
	/** @var bool */
	private $forceLanguage = false;

	/** @var UUID */
	private $serverID;

	/** @var \DynamicClassLoader */
	private $autoloader;
	/** @var string */
	private $dataPath;
	/** @var string */
	private $pluginPath;

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private $uniquePlayers = [];

	/** @var QueryInfo */
	private $queryInfo;

	/** @var ServerConfigGroup */
	private $configGroup;

	/** @var Player[] */
	private $playerList = [];

	/**
	 * @var CommandSender[][]
	 * @phpstan-var array<string, array<int, CommandSender>>
	 */
	private $broadcastSubscribers = [];

	public function getName() : string{
		return VersionInfo::NAME;
	}

	public function isRunning() : bool{
		return $this->isRunning;
	}

	public function getPocketFuzyVersion() : string{
		return VersionInfo::getVersionObj()->getFullVersion(true);
	}

	public function getVersion() : string{
		return ProtocolInfo::MINECRAFT_VERSION;
	}

	public function getApiVersion() : string{
		return VersionInfo::BASE_VERSION;
	}

	public function getFilePath() : string{
		return \PocketFuzy\PATH;
	}

	public function getResourcePath() : string{
		return \PocketFuzy\RESOURCE_PATH;
	}

	public function getDataPath() : string{
		return $this->dataPath;
	}

	public function getPluginPath() : string{
		return $this->pluginPath;
	}

	public function getMaxPlayers() : int{
		return $this->maxPlayers;
	}

	/**
	 * Returns whether the server requires that players be authenticated to Xbox Live. If true, connecting players who
	 * are not logged into Xbox Live will be disconnected.
	 */
	public function getOnlineMode() : bool{
		return $this->onlineMode;
	}

	/**
	 * Alias of {@link #getOnlineMode()}.
	 */
	public function requiresAuthentication() : bool{
		return $this->getOnlineMode();
	}

	public function getPort() : int{
		return $this->configGroup->getConfigInt("server-port", 19132);
	}

	public function getViewDistance() : int{
		return max(2, $this->configGroup->getConfigInt("view-distance", 8));
	}

	/**
	 * Returns a view distance up to the currently-allowed limit.
	 */
	public function getAllowedViewDistance(int $distance) : int{
		return max(2, min($distance, $this->memoryManager->getViewDistance($this->getViewDistance())));
	}

	public function getIp() : string{
		$str = $this->configGroup->getConfigString("server-ip");
		return $str !== "" ? $str : "0.0.0.0";
	}

	/**
	 * @return UUID
	 */
	public function getServerUniqueId(){
		return $this->serverID;
	}

	public function getGamemode() : GameMode{
		return GameMode::fromMagicNumber($this->configGroup->getConfigInt("gamemode", 0) & 0b11);
	}

	public function getForceGamemode() : bool{
		return $this->configGroup->getConfigBool("force-gamemode", false);
	}

	/**
	 * Returns Server global difficulty. Note that this may be overridden in individual worlds.
	 */
	public function getDifficulty() : int{
		return $this->configGroup->getConfigInt("difficulty", World::DIFFICULTY_NORMAL);
	}

	public function hasWhitelist() : bool{
		return $this->configGroup->getConfigBool("white-list", false);
	}

	public function isHardcore() : bool{
		return $this->configGroup->getConfigBool("hardcore", false);
	}

	public function getMotd() : string{
		return $this->configGroup->getConfigString("motd", VersionInfo::NAME . " Server");
	}

	/**
	 * @return \DynamicClassLoader
	 */
	public function getLoader(){
		return $this->autoloader;
	}

	/**
	 * @return \AttachableThreadedLogger
	 */
	public function getLogger(){
		return $this->logger;
	}

	/**
	 * @return AutoUpdater
	 */
	public function getUpdater(){
		return $this->updater;
	}

	/**
	 * @return PluginManager
	 */
	public function getPluginManager(){
		return $this->pluginManager;
	}
	
	/**
	 * Returns the underlying server-scoped API map
	 */
	public function getApiMap() : ApiMap{
		return $this->apiMap;
	}

	/**
	 * Provides an API implementation of $interface.
	 *
	 * $interface can be either an (abstract, open or final) class or interface.
	 * $impl must implement $interface.
	 *
	 * The $interface is used to identify various API types,
	 * and users should not try to provide APIs for two $interfaces that extend one another.
	 * For example, PocketFuzy can provide a default BanList interface provides an interface to track user bans,
	 * which calls `provideApi(BanList::class)`, but it does not call `provideApi(DefaultBanList::class)`.
	 * Nevertheless, if alternative implementations are not intended,
	 * it is fine to provide $interface as the final class.
	 * (TODO: change "can provide" to "provides" when the BanList API is changed)
	 *
	 * If the declaration of `$interface` has a `@purpose` tag in its documentation,
	 * it is provided as part of the error message to describe the purpose of the interface
	 * when two conflicting implementations are provided,
	 * e.g. `@purpose ban list` would result in the error:
	 *
	 * > Multiple plugins are providing ban list. Please disable one of them or check configuration
	 *
	 * The default implementation (usually provided by the module declaring the interface)
	 * should call `provideDefaultApi` so that other plugins can override it without triggering errors.
	 *
	 * @phpstan-template T of object
	 * @phpstan-param class-string<T> $interface
	 * @phpstan-param T $impl
	 *
	 * @throws \InvalidArgumentException if $impl is not an instance of $interface
	 * @throws \RuntimeException if two non-default APIs are provided for the same interface
	 *
	 * @see Server::provideDefaultApi()
	 */
	public function provideApi(string $interface, Plugin $plugin, object $impl) : void{
		$this->apiMap->provideApi($interface, $plugin, $impl, false);
	}

	/**
	 * Provides a *default* API implementation of $interface.
	 *
	 * `provideDefaultApi` must only be called exactly once, by the module that declared `$interface`.
	 *
	 * @phpstan-template T of object
	 * @phpstan-param class-string<T> $interface
	 * @phpstan-param T $impl
	 *
	 * @throws \InvalidArgumentException if $impl is not an instance of $interface
	 *
	 * @see Server::provideApi() for detailed semantics of API provision
	 */
	public function provideDefaultApi(string $interface, Plugin $plugin, object $impl) : void{
		$this->apiMap->provideApi($interface, $plugin, $impl, true);
	}

	/**
	 * Retrieves the current implementation of `getApi`.
	 *
	 * Callers can check whether this implementation is default by getting `$default` by reference.
	 *
	 * @phpstan-template T of object
	 * @phpstan-param class-string<T> $interface
	 * @phpstan-return T|null
	 */
	public function getApi(string $interface, bool &$default = false) : ?object{
		return $this->apiMap->getApi($interface, $default);
	}

	/**
	 * @return CraftingManager
	 */
	public function getCraftingManager(){
		return $this->craftingManager;
	}

	public function getResourcePackManager() : ResourcePackManager{
		return $this->resourceManager;
	}

	public function getWorldManager() : WorldManager{
		return $this->worldManager;
	}

	public function getAsyncPool() : AsyncPool{
		return $this->asyncPool;
	}

	public function getTick() : int{
		return $this->tickCounter;
	}

	/**
	 * Returns the last server TPS measure
	 */
	public function getTicksPerSecond() : float{
		return round($this->currentTPS, 2);
	}

	/**
	 * Returns the last server TPS average measure
	 */
	public function getTicksPerSecondAverage() : float{
		return round(array_sum($this->tickAverage) / count($this->tickAverage), 2);
	}

	/**
	 * Returns the TPS usage/load in %
	 */
	public function getTickUsage() : float{
		return round($this->currentUse * 100, 2);
	}

	/**
	 * Returns the TPS usage/load average in %
	 */
	public function getTickUsageAverage() : float{
		return round((array_sum($this->useAverage) / count($this->useAverage)) * 100, 2);
	}

	public function getStartTime() : float{
		return $this->startTime;
	}

	/**
	 * @return SimpleCommandMap
	 */
	public function getCommandMap(){
		return $this->commandMap;
	}

	/**
	 * @return Player[]
	 */
	public function getOnlinePlayers() : array{
		return $this->playerList;
	}

	public function shouldSavePlayerData() : bool{
		return (bool) $this->configGroup->getProperty("player.save-player-data", true);
	}

	/**
	 * @return OfflinePlayer|Player
	 */
	public function getOfflinePlayer(string $name){
		$name = strtolower($name);
		$result = $this->getPlayerExact($name);

		if($result === null){
			$result = new OfflinePlayer($name, $this->getOfflinePlayerData($name));
		}

		return $result;
	}

	private function getPlayerDataPath(string $username) : string{
		return $this->getDataPath() . '/players/' . strtolower($username) . '.dat';
	}

	/**
	 * Returns whether the server has stored any saved data for this player.
	 */
	public function hasOfflinePlayerData(string $name) : bool{
		return file_exists($this->getPlayerDataPath($name));
	}

	private function handleCorruptedPlayerData(string $name) : void{
		$path = $this->getPlayerDataPath($name);
		rename($path, $path . '.bak');
		$this->logger->error($this->getLanguage()->translateString("PocketFuzy.data.playerCorrupted", [$name]));
	}

	public function getOfflinePlayerData(string $name) : ?CompoundTag{
		return Timings::$syncPlayerDataLoad->time(function() use ($name) : ?CompoundTag{
			$name = strtolower($name);
			$path = $this->getPlayerDataPath($name);

			if(file_exists($path)){
				$contents = @file_get_contents($path);
				if($contents === false){
					throw new \RuntimeException("Failed to read player data file \"$path\" (permission denied?)");
				}
				$decompressed = @zlib_decode($contents);
				if($decompressed === false){
					$this->logger->debug("Failed to decompress raw player data for \"$name\"");
					$this->handleCorruptedPlayerData($name);
					return null;
				}

				try{
					return (new BigEndianNbtSerializer())->read($decompressed)->mustGetCompoundTag();
				}catch(NbtDataException $e){ //corrupt data
					$this->logger->debug("Failed to decode NBT data for \"$name\": " . $e->getMessage());
					$this->handleCorruptedPlayerData($name);
					return null;
				}
			}
			return null;
		});
	}

	public function saveOfflinePlayerData(string $name, CompoundTag $nbtTag) : void{
		$ev = new PlayerDataSaveEvent($nbtTag, $name, $this->getPlayerExact($name));
		if(!$this->shouldSavePlayerData()){
			$ev->cancel();
		}

		$ev->call();

		if(!$ev->isCancelled()){
			Timings::$syncPlayerDataSave->time(function() use ($name, $ev) : void{
				$nbt = new BigEndianNbtSerializer();
				try{
					file_put_contents($this->getPlayerDataPath($name), zlib_encode($nbt->write(new TreeRoot($ev->getSaveData())), ZLIB_ENCODING_GZIP));
				}catch(\ErrorException $e){
					$this->logger->critical($this->getLanguage()->translateString("PocketFuzy.data.saveError", [$name, $e->getMessage()]));
					$this->logger->logException($e);
				}
			});
		}
	}

	public function createPlayer(NetworkSession $session, PlayerInfo $playerInfo, bool $authenticated, ?CompoundTag $offlinePlayerData) : Player{
		$ev = new PlayerCreationEvent($session);
		$ev->call();
		$class = $ev->getPlayerClass();

		/**
		 * @see Player::__construct()
		 * @var Player $player
		 */
		$player = new $class($this, $session, $playerInfo, $authenticated, $offlinePlayerData);
		return $player;
	}

	/**
	 * Returns an online player whose name begins with or equals the given string (case insensitive).
	 * The closest match will be returned, or null if there are no online matches.
	 *
	 * @see Server::getPlayerExact()
	 */
	public function getPlayerByPrefix(string $name) : ?Player{
		$found = null;
		$name = strtolower($name);
		$delta = PHP_INT_MAX;
		foreach($this->getOnlinePlayers() as $player){
			if(stripos($player->getName(), $name) === 0){
				$curDelta = strlen($player->getName()) - strlen($name);
				if($curDelta < $delta){
					$found = $player;
					$delta = $curDelta;
				}
				if($curDelta === 0){
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Returns an online player with the given name (case insensitive), or null if not found.
	 */
	public function getPlayerExact(string $name) : ?Player{
		$name = strtolower($name);
		foreach($this->getOnlinePlayers() as $player){
			if(strtolower($player->getName()) === $name){
				return $player;
			}
		}

		return null;
	}

	/**
	 * Returns the player online with the specified raw UUID, or null if not found
	 */
	public function getPlayerByRawUUID(string $rawUUID) : ?Player{
		return $this->playerList[$rawUUID] ?? null;
	}

	/**
	 * Returns the player online with a UUID equivalent to the specified UUID object, or null if not found
	 */
	public function getPlayerByUUID(UUID $uuid) : ?Player{
		return $this->getPlayerByRawUUID($uuid->toBinary());
	}

	public function getConfigGroup() : ServerConfigGroup{
		return $this->configGroup;
	}

	/**
	 * @return Command|PluginOwned|null
	 * @phpstan-return (Command&PluginOwned)|null
	 */
	public function getPluginCommand(string $name){
		if(($command = $this->commandMap->getCommand($name)) instanceof PluginOwned){
			return $command;
		}else{
			return null;
		}
	}

	/**
	 * @return BanList
	 */
	public function getNameBans(){
		return $this->banByName;
	}

	/**
	 * @return BanList
	 */
	public function getIPBans(){
		return $this->banByIP;
	}

	public function addOp(string $name) : void{
		$this->operators->set(strtolower($name), true);

		if(($player = $this->getPlayerExact($name)) !== null){
			$player->setBasePermission(DefaultPermissions::ROOT_OPERATOR, true);
		}
		$this->operators->save();
	}

	public function removeOp(string $name) : void{
		$this->operators->remove(strtolower($name));

		if(($player = $this->getPlayerExact($name)) !== null){
			$player->unsetBasePermission(DefaultPermissions::ROOT_OPERATOR);
		}
		$this->operators->save();
	}

	public function addWhitelist(string $name) : void{
		$this->whitelist->set(strtolower($name), true);
		$this->whitelist->save();
	}

	public function removeWhitelist(string $name) : void{
		$this->whitelist->remove(strtolower($name));
		$this->whitelist->save();
	}

	public function isWhitelisted(string $name) : bool{
		return !$this->hasWhitelist() or $this->operators->exists($name, true) or $this->whitelist->exists($name, true);
	}

	public function isOp(string $name) : bool{
		return $this->operators->exists($name, true);
	}

	/**
	 * @return Config
	 */
	public function getWhitelisted(){
		return $this->whitelist;
	}

	/**
	 * @return Config
	 */
	public function getOps(){
		return $this->operators;
	}

	/**
	 * @return string[][]
	 */
	public function getCommandAliases() : array{
		$section = $this->configGroup->getProperty("aliases");
		$result = [];
		if(is_array($section)){
			foreach($section as $key => $value){
				$commands = [];
				if(is_array($value)){
					$commands = $value;
				}else{
					$commands[] = (string) $value;
				}

				$result[$key] = $commands;
			}
		}

		return $result;
	}

	public static function getInstance() : Server{
		if(self::$instance === null){
			throw new \RuntimeException("Attempt to retrieve Server instance outside server thread");
		}
		return self::$instance;
	}

	public function __construct(\DynamicClassLoader $autoloader, \AttachableThreadedLogger $logger, string $dataPath, string $pluginPath){
		if(self::$instance !== null){
			throw new \InvalidStateException("Only one server instance can exist at once");
		}
		self::$instance = $this;
		$this->startTime = microtime(true);

		$this->tickSleeper = new SleeperHandler();
		$this->autoloader = $autoloader;
		$this->logger = $logger;

		try{
			if(!file_exists($dataPath . "worlds/")){
				mkdir($dataPath . "worlds/", 0777);
			}

			if(!file_exists($dataPath . "players/")){
				mkdir($dataPath . "players/", 0777);
			}

			if(!file_exists($pluginPath)){
				mkdir($pluginPath, 0777);
			}

			$this->dataPath = realpath($dataPath) . DIRECTORY_SEPARATOR;
			$this->pluginPath = realpath($pluginPath) . DIRECTORY_SEPARATOR;

			$this->logger->info("Loading server configuration");
			if(!file_exists($this->dataPath . "PocketFuzy.yml")){
				$content = file_get_contents(\PocketFuzy\RESOURCE_PATH . "PocketFuzy.yml");
				if(VersionInfo::IS_DEVELOPMENT_BUILD){
					$content = str_replace("preferred-channel: stable", "preferred-channel: beta", $content);
				}
				@file_put_contents($this->dataPath . "PocketFuzy.yml", $content);
			}

			$this->configGroup = new ServerConfigGroup(
				new Config($this->dataPath . "PocketFuzy.yml", Config::YAML, []),
				new Config($this->dataPath . "server.properties", Config::PROPERTIES, [
					"motd" => VersionInfo::NAME . " Server",
					"server-port" => 19132,
					"white-list" => false,
					"max-players" => 20,
					"gamemode" => 0,
					"force-gamemode" => false,
					"hardcore" => false,
					"pvp" => true,
					"difficulty" => World::DIFFICULTY_NORMAL,
					"generator-settings" => "",
					"level-name" => "world",
					"level-seed" => "",
					"level-type" => "DEFAULT",
					"enable-query" => true,
					"auto-save" => true,
					"view-distance" => 8,
					"xbox-auth" => true,
					"language" => "eng"
				])
			);

			$debugLogLevel = (int) $this->configGroup->getProperty("debug.level", 1);
			if($this->logger instanceof MainLogger){
				$this->logger->setLogDebug($debugLogLevel > 1);
			}

			$this->forceLanguage = (bool) $this->configGroup->getProperty("settings.force-language", false);
			$selectedLang = $this->configGroup->getConfigString("language", $this->configGroup->getProperty("settings.language", Language::FALLBACK_LANGUAGE));
			try{
				$this->language = new Language($selectedLang);
			}catch(LanguageNotFoundException $e){
				$this->logger->error($e->getMessage());
				try{
					$this->language = new Language(Language::FALLBACK_LANGUAGE);
				}catch(LanguageNotFoundException $e){
					$this->logger->emergency("Fallback language \"" . Language::FALLBACK_LANGUAGE . "\" not found");
					return;
				}
			}

			$this->logger->info($this->getLanguage()->translateString("language.selected", [$this->getLanguage()->getName(), $this->getLanguage()->getLang()]));

			if(VersionInfo::IS_DEVELOPMENT_BUILD){
				if(!((bool) $this->configGroup->getProperty("settings.enable-dev-builds", false))){
					$this->logger->emergency($this->language->translateString("PocketFuzy.server.devBuild.error1", [VersionInfo::NAME]));
					$this->logger->emergency($this->language->translateString("PocketFuzy.server.devBuild.error2"));
					$this->logger->emergency($this->language->translateString("PocketFuzy.server.devBuild.error3"));
					$this->logger->emergency($this->language->translateString("PocketFuzy.server.devBuild.error4", ["settings.enable-dev-builds"]));
					$this->logger->emergency($this->language->translateString("PocketFuzy.server.devBuild.error5", ["https://github.com/pmmp/PocketFuzy/releases"]));
					$this->forceShutdown();

					return;
				}

				$this->logger->warning(str_repeat("-", 40));
				$this->logger->warning($this->language->translateString("PocketFuzy.server.devBuild.warning1", [VersionInfo::NAME]));
				$this->logger->warning($this->language->translateString("PocketFuzy.server.devBuild.warning2"));
				$this->logger->warning($this->language->translateString("PocketFuzy.server.devBuild.warning3"));
				$this->logger->warning(str_repeat("-", 40));
			}

			$this->memoryManager = new MemoryManager($this);

			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.start", [TextFormat::AQUA . $this->getVersion() . TextFormat::RESET]));

			if(($poolSize = $this->configGroup->getProperty("settings.async-workers", "auto")) === "auto"){
				$poolSize = 2;
				$processors = Utils::getCoreCount() - 2;

				if($processors > 0){
					$poolSize = max(1, $processors);
				}
			}else{
				$poolSize = max(1, (int) $poolSize);
			}

			$this->asyncPool = new AsyncPool($poolSize, max(-1, (int) $this->configGroup->getProperty("memory.async-worker-hard-limit", 256)), $this->autoloader, $this->logger, $this->tickSleeper);

			$netCompressionThreshold = -1;
			if($this->configGroup->getProperty("network.batch-threshold", 256) >= 0){
				$netCompressionThreshold = (int) $this->configGroup->getProperty("network.batch-threshold", 256);
			}

			$netCompressionLevel = (int) $this->configGroup->getProperty("network.compression-level", 6);
			if($netCompressionLevel < 1 or $netCompressionLevel > 9){
				$this->logger->warning("Invalid network compression level $netCompressionLevel set, setting to default 6");
				$netCompressionLevel = 6;
			}
			ZlibCompressor::setInstance(new ZlibCompressor($netCompressionLevel, $netCompressionThreshold, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));

			$this->networkCompressionAsync = (bool) $this->configGroup->getProperty("network.async-compression", true);

			EncryptionContext::$ENABLED = (bool) $this->configGroup->getProperty("network.enable-encryption", true);

			$this->doTitleTick = ((bool) $this->configGroup->getProperty("console.title-tick", true)) && Terminal::hasFormattingCodes();

			$this->operators = new Config($this->dataPath . "ops.txt", Config::ENUM);
			$this->whitelist = new Config($this->dataPath . "white-list.txt", Config::ENUM);
			if(file_exists($this->dataPath . "banned.txt") and !file_exists($this->dataPath . "banned-players.txt")){
				@rename($this->dataPath . "banned.txt", $this->dataPath . "banned-players.txt");
			}
			@touch($this->dataPath . "banned-players.txt");
			$this->banByName = new BanList($this->dataPath . "banned-players.txt");
			$this->banByName->load();
			@touch($this->dataPath . "banned-ips.txt");
			$this->banByIP = new BanList($this->dataPath . "banned-ips.txt");
			$this->banByIP->load();

			$this->maxPlayers = $this->configGroup->getConfigInt("max-players", 20);

			$this->onlineMode = $this->configGroup->getConfigBool("xbox-auth", true);
			if($this->onlineMode){
				$this->logger->notice($this->getLanguage()->translateString("PocketFuzy.server.auth.enabled"));
				$this->logger->notice($this->getLanguage()->translateString("PocketFuzy.server.authProperty.enabled"));
			}else{
				$this->logger->warning($this->getLanguage()->translateString("PocketFuzy.server.auth.disabled"));
				$this->logger->warning($this->getLanguage()->translateString("PocketFuzy.server.authWarning"));
				$this->logger->warning($this->getLanguage()->translateString("PocketFuzy.server.authProperty.disabled"));
			}

			if($this->configGroup->getConfigBool("hardcore", false) and $this->getDifficulty() < World::DIFFICULTY_HARD){
				$this->configGroup->setConfigInt("difficulty", World::DIFFICULTY_HARD);
			}

			@cli_set_process_title($this->getName() . " " . $this->getPocketFuzyVersion());

			$this->serverID = Utils::getMachineUniqueId($this->getIp() . $this->getPort());

			$this->getLogger()->debug("Server unique id: " . $this->getServerUniqueId());
			$this->getLogger()->debug("Machine unique id: " . Utils::getMachineUniqueId());

			$this->network = new Network($this->logger);
			$this->network->setName($this->getMotd());

			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.info", [
				$this->getName(),
				(VersionInfo::IS_DEVELOPMENT_BUILD ? TextFormat::YELLOW : "") . $this->getPocketFuzyVersion() . TextFormat::RESET
			]));
			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.license", [$this->getName()]));

			Timings::init();
			TimingsHandler::setEnabled((bool) $this->configGroup->getProperty("settings.enable-profiling", false));
			$this->profilingTickRate = (float) $this->configGroup->getProperty("settings.profile-report-trigger", 20);

			DefaultPermissions::registerCorePermissions();

			$this->commandMap = new SimpleCommandMap($this);

			$this->craftingManager = CraftingManagerFromDataHelper::make(\PocketFuzy\RESOURCE_PATH . '/vanilla/recipes.json');

			$this->resourceManager = new ResourcePackManager($this->getDataPath() . "resource_packs" . DIRECTORY_SEPARATOR, $this->logger);

			$pluginGraylist = null;
			$graylistFile = $this->dataPath . "plugin_list.yml";
			if(!file_exists($graylistFile)){
				copy(\PocketFuzy\RESOURCE_PATH . 'plugin_list.yml', $graylistFile);
			}
			try{
				$pluginGraylist = PluginGraylist::fromArray(yaml_parse(file_get_contents($graylistFile)));
			}catch(\InvalidArgumentException $e){
				$this->logger->emergency("Failed to load $graylistFile: " . $e->getMessage());
				$this->forceShutdown();
				return;
			}
			$this->pluginManager = new PluginManager($this, ((bool) $this->configGroup->getProperty("plugins.legacy-data-dir", true)) ? null : $this->getDataPath() . "plugin_data" . DIRECTORY_SEPARATOR, $pluginGraylist);
			$this->pluginManager->registerInterface(new PharPluginLoader($this->autoloader));
			$this->pluginManager->registerInterface(new ScriptPluginLoader());

			$this->apiMap = new ApiMap;

			$providerManager = new WorldProviderManager();
			if(
				($format = $providerManager->getProviderByName($formatName = (string) $this->configGroup->getProperty("level-settings.default-format"))) !== null and
				is_a($format, WritableWorldProvider::class, true)
			){
				$providerManager->setDefault($format);
			}elseif($formatName !== ""){
				$this->logger->warning($this->language->translateString("PocketFuzy.level.badDefaultFormat", [$formatName]));
			}

			$this->worldManager = new WorldManager($this, $this->dataPath . "/worlds", $providerManager);
			$this->worldManager->setAutoSave($this->configGroup->getConfigBool("auto-save", $this->worldManager->getAutoSave()));
			$this->worldManager->setAutoSaveInterval((int) $this->configGroup->getProperty("ticks-per.autosave", 6000));

			$this->updater = new AutoUpdater($this, $this->configGroup->getProperty("auto-updater.host", "update.pmmp.io"));

			$this->queryInfo = new QueryInfo($this);

			register_shutdown_function([$this, "crashDump"]);

			$this->pluginManager->loadPlugins($this->pluginPath);
			$this->enablePlugins(PluginEnableOrder::STARTUP());

			foreach((array) $this->configGroup->getProperty("worlds", []) as $name => $options){
				if($options === null){
					$options = [];
				}elseif(!is_array($options)){
					continue;
				}
				if(!$this->worldManager->loadWorld($name, true)){
					if(isset($options["generator"])){
						$generatorOptions = explode(":", $options["generator"]);
						$generator = GeneratorManager::getInstance()->getGenerator(array_shift($generatorOptions));
						if(count($generatorOptions) > 0){
							$options["preset"] = implode(":", $generatorOptions);
						}
					}else{
						$generator = Normal::class;
					}

					$this->worldManager->generateWorld($name, Generator::convertSeed((string) ($options["seed"] ?? "")), $generator, $options);
				}
			}

			if($this->worldManager->getDefaultWorld() === null){
				$default = $this->configGroup->getConfigString("level-name", "world");
				if(trim($default) == ""){
					$this->getLogger()->warning("level-name cannot be null, using default");
					$default = "world";
					$this->configGroup->setConfigString("level-name", "world");
				}
				if(!$this->worldManager->loadWorld($default, true)){
					$this->worldManager->generateWorld(
						$default,
						Generator::convertSeed($this->configGroup->getConfigString("level-seed")),
						GeneratorManager::getInstance()->getGenerator($this->configGroup->getConfigString("level-type")),
						["preset" => $this->configGroup->getConfigString("generator-settings")]
					);
				}

				$world = $this->worldManager->getWorldByName($default);
				if($world === null){
					$this->getLogger()->emergency($this->getLanguage()->translateString("PocketFuzy.level.defaultError"));
					$this->forceShutdown();

					return;
				}
				$this->worldManager->setDefaultWorld($world);
			}

			$this->enablePlugins(PluginEnableOrder::POSTWORLD());

			$useQuery = $this->configGroup->getConfigBool("enable-query", true);
			if(!$this->network->registerInterface(new RakLibInterface($this)) && $useQuery){
				//RakLib would normally handle the transport for Query packets
				//if it's not registered we need to make sure Query still works
				$this->network->registerInterface(new DedicatedQueryNetworkInterface($this->getIp(), $this->getPort(), new \PrefixedLogger($this->logger, "Dedicated Query Interface")));
			}
			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.networkStart", [$this->getIp(), $this->getPort()]));

			if($useQuery){
				$this->network->registerRawPacketHandler(new QueryHandler($this));
			}

			foreach($this->getIPBans()->getEntries() as $entry){
				$this->network->blockAddress($entry->getName(), -1);
			}

			if((bool) $this->configGroup->getProperty("network.upnp-forwarding", false)){
				try{
					$this->network->registerInterface(new UPnP($this->logger, Internet::getInternalIP(), $this->getPort()));
				}catch(\RuntimeException $e){
					$this->logger->alert("UPnP portforward failed: " . $e->getMessage());
				}
			}

			if((bool) $this->configGroup->getProperty("settings.send-usage", true)){
				$this->sendUsageTicker = 6000;
				$this->sendUsage(SendUsageTask::TYPE_OPEN);
			}

			$this->configGroup->save();

			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.defaultGameMode", [$this->getGamemode()->getTranslationKey()]));
			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.donate", [TextFormat::AQUA . "https://patreon.com/PocketFuzymp" . TextFormat::RESET]));
			$this->logger->info($this->getLanguage()->translateString("PocketFuzy.server.startFinished", [round(microtime(true) - $this->startTime, 3)]));

			//TODO: move console parts to a separate component
			$consoleSender = new ConsoleCommandSender($this, $this->language);
			$consoleSender->recalculatePermissions();
			$this->subscribeToBroadcastChannel(self::BROADCAST_CHANNEL_ADMINISTRATIVE, $consoleSender);
			$this->subscribeToBroadcastChannel(self::BROADCAST_CHANNEL_USERS, $consoleSender);

			$consoleNotifier = new SleeperNotifier();
			$this->console = new CommandReader($consoleNotifier);
			$this->tickSleeper->addNotifier($consoleNotifier, function() use ($consoleSender) : void{
				Timings::$serverCommand->startTiming();
				while(($line = $this->console->getLine()) !== null){
					$this->dispatchCommand($consoleSender, $line);
				}
				Timings::$serverCommand->stopTiming();
			});
			$this->console->start(PTHREADS_INHERIT_NONE);

			$this->tickProcessor();
			$this->forceShutdown();
		}catch(\Throwable $e){
			$this->exceptionHandler($e);
		}
	}

	/**
	 * Subscribes to a particular message broadcast channel.
	 * The channel ID can be any arbitrary string.
	 */
	public function subscribeToBroadcastChannel(string $channelId, CommandSender $subscriber) : void{
		$this->broadcastSubscribers[$channelId][spl_object_id($subscriber)] = $subscriber;
	}

	/**
	 * Unsubscribes from a particular message broadcast channel.
	 */
	public function unsubscribeFromBroadcastChannel(string $channelId, CommandSender $subscriber) : void{
		if(isset($this->broadcastSubscribers[$channelId][spl_object_id($subscriber)])){
			unset($this->broadcastSubscribers[$channelId][spl_object_id($subscriber)]);
			if(count($this->broadcastSubscribers[$channelId]) === 0){
				unset($this->broadcastSubscribers[$channelId]);
			}
		}
	}

	/**
	 * Unsubscribes from all broadcast channels.
	 */
	public function unsubscribeFromAllBroadcastChannels(CommandSender $subscriber) : void{
		foreach($this->broadcastSubscribers as $channelId => $recipients){
			$this->unsubscribeFromBroadcastChannel($channelId, $subscriber);
		}
	}

	/**
	 * Returns a list of all the CommandSenders subscribed to the given broadcast channel.
	 *
	 * @return CommandSender[]
	 * @phpstan-return array<int, CommandSender>
	 */
	public function getBroadcastChannelSubscribers(string $channelId) : array{
		return $this->broadcastSubscribers[$channelId] ?? [];
	}

	/**
	 * @param TranslationContainer|string $message
	 * @param CommandSender[]|null        $recipients
	 */
	public function broadcastMessage($message, ?array $recipients = null) : int{
		$recipients = $recipients ?? $this->getBroadcastChannelSubscribers(self::BROADCAST_CHANNEL_USERS);

		foreach($recipients as $recipient){
			$recipient->sendMessage($message);
		}

		return count($recipients);
	}

	/**
	 * @return Player[]
	 */
	private function getPlayerBroadcastSubscribers(string $channelId) : array{
		/** @var Player[] $players */
		$players = [];
		foreach($this->broadcastSubscribers[$channelId] as $subscriber){
			if($subscriber instanceof Player){
				$players[spl_object_id($subscriber)] = $subscriber;
			}
		}
		return $players;
	}

	/**
	 * @param Player[]|null $recipients
	 */
	public function broadcastTip(string $tip, ?array $recipients = null) : int{
		$recipients = $recipients ?? $this->getPlayerBroadcastSubscribers(self::BROADCAST_CHANNEL_USERS);

		foreach($recipients as $recipient){
			$recipient->sendTip($tip);
		}

		return count($recipients);
	}

	/**
	 * @param Player[]|null $recipients
	 */
	public function broadcastPopup(string $popup, ?array $recipients = null) : int{
		$recipients = $recipients ?? $this->getPlayerBroadcastSubscribers(self::BROADCAST_CHANNEL_USERS);

		foreach($recipients as $recipient){
			$recipient->sendPopup($popup);
		}

		return count($recipients);
	}

	/**
	 * @param int           $fadeIn Duration in ticks for fade-in. If -1 is given, client-sided defaults will be used.
	 * @param int           $stay Duration in ticks to stay on screen for
	 * @param int           $fadeOut Duration in ticks for fade-out.
	 * @param Player[]|null $recipients
	 */
	public function broadcastTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1, ?array $recipients = null) : int{
		$recipients = $recipients ?? $this->getPlayerBroadcastSubscribers(self::BROADCAST_CHANNEL_USERS);

		foreach($recipients as $recipient){
			$recipient->sendTitle($title, $subtitle, $fadeIn, $stay, $fadeOut);
		}

		return count($recipients);
	}

	/**
	 * @param Player[]            $players
	 * @param ClientboundPacket[] $packets
	 */
	public function broadcastPackets(array $players, array $packets) : bool{
		if(count($packets) === 0){
			throw new \InvalidArgumentException("Cannot broadcast empty list of packets");
		}

		return Timings::$broadcastPackets->time(function() use ($players, $packets) : bool{
			/** @var NetworkSession[] $recipients */
			$recipients = [];
			foreach($players as $player){
				if($player->isConnected()){
					$recipients[] = $player->getNetworkSession();
				}
			}
			if(count($recipients) === 0){
				return false;
			}

			$ev = new DataPacketSendEvent($recipients, $packets);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
			$recipients = $ev->getTargets();

			/** @var PacketBroadcaster[] $broadcasters */
			$broadcasters = [];
			/** @var NetworkSession[][] $broadcasterTargets */
			$broadcasterTargets = [];
			foreach($recipients as $recipient){
				$broadcaster = $recipient->getBroadcaster();
				$broadcasters[spl_object_id($broadcaster)] = $broadcaster;
				$broadcasterTargets[spl_object_id($broadcaster)][] = $recipient;
			}
			foreach($broadcasters as $broadcaster){
				$broadcaster->broadcastPackets($broadcasterTargets[spl_object_id($broadcaster)], $packets);
			}

			return true;
		});
	}

	/**
	 * Broadcasts a list of packets in a batch to a list of players
	 *
	 * @param bool|null $sync Compression on the main thread (true) or workers (false). Default is automatic (null).
	 */
	public function prepareBatch(PacketBatch $stream, Compressor $compressor, ?bool $sync = null) : CompressBatchPromise{
		try{
			Timings::$playerNetworkSendCompress->startTiming();

			$buffer = $stream->getBuffer();

			if($sync === null){
				$sync = !($this->networkCompressionAsync && $compressor->willCompress($buffer));
			}

			$promise = new CompressBatchPromise();
			if(!$sync){
				$task = new CompressBatchTask($buffer, $promise, $compressor);
				$this->asyncPool->submitTask($task);
			}else{
				$promise->resolve($compressor->compress($buffer));
			}

			return $promise;
		}finally{
			Timings::$playerNetworkSendCompress->stopTiming();
		}
	}

	public function enablePlugins(PluginEnableOrder $type) : void{
		foreach($this->pluginManager->getPlugins() as $plugin){
			if(!$plugin->isEnabled() and $plugin->getDescription()->getOrder()->equals($type)){
				$this->pluginManager->enablePlugin($plugin);
			}
		}

		if($type->equals(PluginEnableOrder::POSTWORLD())){
			$this->commandMap->registerServerAliases();
		}
	}

	/**
	 * Executes a command from a CommandSender
	 */
	public function dispatchCommand(CommandSender $sender, string $commandLine, bool $internal = false) : bool{
		if(!$internal){
			$ev = new CommandEvent($sender, $commandLine);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}

			$commandLine = $ev->getCommand();
		}

		if($this->commandMap->dispatch($sender, $commandLine)){
			return true;
		}

		$sender->sendMessage($sender->getLanguage()->translateString(TextFormat::RED . "%commands.generic.notFound"));

		return false;
	}

	/**
	 * Shuts the server down correctly
	 */
	public function shutdown() : void{
		$this->isRunning = false;
	}

	public function forceShutdown() : void{
		if($this->hasStopped){
			return;
		}

		if($this->doTitleTick){
			echo "\x1b]0;\x07";
		}

		try{
			if(!$this->isRunning()){
				$this->sendUsage(SendUsageTask::TYPE_CLOSE);
			}

			$this->hasStopped = true;

			$this->shutdown();

			if($this->pluginManager instanceof PluginManager){
				$this->getLogger()->debug("Disabling all plugins");
				$this->pluginManager->disablePlugins();
			}

			if($this->network instanceof Network){
				$this->network->getSessionManager()->close($this->configGroup->getProperty("settings.shutdown-message", "Server closed"));
			}

			if($this->worldManager instanceof WorldManager){
				$this->getLogger()->debug("Unloading all worlds");
				foreach($this->worldManager->getWorlds() as $world){
					$this->worldManager->unloadWorld($world, true);
				}
			}

			$this->getLogger()->debug("Removing event handlers");
			HandlerListManager::global()->unregisterAll();

			if($this->asyncPool instanceof AsyncPool){
				$this->getLogger()->debug("Shutting down async task worker pool");
				$this->asyncPool->shutdown();
			}

			if($this->configGroup !== null){
				$this->getLogger()->debug("Saving properties");
				$this->configGroup->save();
			}

			if($this->console instanceof CommandReader){
				$this->getLogger()->debug("Closing console");
				$this->console->shutdown();
				$this->console->notify();
			}

			if($this->network instanceof Network){
				$this->getLogger()->debug("Stopping network interfaces");
				foreach($this->network->getInterfaces() as $interface){
					$this->getLogger()->debug("Stopping network interface " . get_class($interface));
					$this->network->unregisterInterface($interface);
				}
			}
		}catch(\Throwable $e){
			$this->logger->logException($e);
			$this->logger->emergency("Crashed while crashing, killing process");
			@Process::kill(Process::pid());
		}

	}

	/**
	 * @return QueryInfo
	 */
	public function getQueryInformation(){
		return $this->queryInfo;
	}

	/**
	 * @param mixed[][]|null $trace
	 * @phpstan-param list<array<string, mixed>>|null $trace
	 */
	public function exceptionHandler(\Throwable $e, $trace = null) : void{
		while(@ob_end_flush()){}
		global $lastError;

		if($trace === null){
			$trace = $e->getTrace();
		}

		$errstr = $e->getMessage();
		$errfile = $e->getFile();
		$errline = $e->getLine();

		$errstr = preg_replace('/\s+/', ' ', trim($errstr));

		$errfile = Filesystem::cleanPath($errfile);

		$this->logger->logException($e, $trace);

		$lastError = [
			"type" => get_class($e),
			"message" => $errstr,
			"fullFile" => $e->getFile(),
			"file" => $errfile,
			"line" => $errline,
			"trace" => $trace
		];

		global $lastExceptionError, $lastError;
		$lastExceptionError = $lastError;
		$this->crashDump();
	}

	public function crashDump() : void{
		while(@ob_end_flush()){}
		if(!$this->isRunning){
			return;
		}
		if($this->sendUsageTicker > 0){
			$this->sendUsage(SendUsageTask::TYPE_CLOSE);
		}
		$this->hasStopped = false;

		ini_set("error_reporting", '0');
		ini_set("memory_limit", '-1'); //Fix error dump not dumped on memory problems
		try{
			$this->logger->emergency($this->getLanguage()->translateString("PocketFuzy.crash.create"));
			$dump = new CrashDump($this);

			$this->logger->emergency($this->getLanguage()->translateString("PocketFuzy.crash.submit", [$dump->getPath()]));

			if($this->configGroup->getProperty("auto-report.enabled", true) !== false){
				$report = true;

				$stamp = $this->getDataPath() . "crashdumps/.last_crash";
				$crashInterval = 120; //2 minutes
				if(file_exists($stamp) and !($report = (filemtime($stamp) + $crashInterval < time()))){
					$this->logger->debug("Not sending crashdump due to last crash less than $crashInterval seconds ago");
				}
				@touch($stamp); //update file timestamp

				$plugin = $dump->getData()["plugin"];
				if(is_string($plugin)){
					$p = $this->pluginManager->getPlugin($plugin);
					if($p instanceof Plugin and !($p->getPluginLoader() instanceof PharPluginLoader)){
						$this->logger->debug("Not sending crashdump due to caused by non-phar plugin");
						$report = false;
					}
				}

				if($dump->getData()["error"]["type"] === \ParseError::class){
					$report = false;
				}

				if(strrpos(VersionInfo::getGitHash(), "-dirty") !== false or VersionInfo::getGitHash() === str_repeat("00", 20)){
					$this->logger->debug("Not sending crashdump due to locally modified");
					$report = false; //Don't send crashdumps for locally modified builds
				}

				if($report){
					$url = ((bool) $this->configGroup->getProperty("auto-report.use-https", true) ? "https" : "http") . "://" . $this->configGroup->getProperty("auto-report.host", "crash.pmmp.io") . "/submit/api";
					$postUrlError = "Unknown error";
					$reply = Internet::postURL($url, [
						"report" => "yes",
						"name" => $this->getName() . " " . $this->getPocketFuzyVersion(),
						"email" => "crash@PocketFuzy.net",
						"reportPaste" => base64_encode($dump->getEncodedData())
					], 10, [], $postUrlError);

					if($reply !== null and ($data = json_decode($reply->getBody())) !== null){
						if(isset($data->crashId) and isset($data->crashUrl)){
							$reportId = $data->crashId;
							$reportUrl = $data->crashUrl;
							$this->logger->emergency($this->getLanguage()->translateString("PocketFuzy.crash.archive", [$reportUrl, $reportId]));
						}elseif(isset($data->error)){
							$this->logger->emergency("Automatic crash report submission failed: $data->error");
						}
					}else{
						$this->logger->emergency("Failed to communicate with crash archive: $postUrlError");
					}
				}
			}
		}catch(\Throwable $e){
			$this->logger->logException($e);
			try{
				$this->logger->critical($this->getLanguage()->translateString("PocketFuzy.crash.error", [$e->getMessage()]));
			}catch(\Throwable $e){}
		}

		$this->forceShutdown();
		$this->isRunning = false;

		//Force minimum uptime to be >= 120 seconds, to reduce the impact of spammy crash loops
		$spacing = ((int) $this->startTime) - time() + 120;
		if($spacing > 0){
			echo "--- Waiting $spacing seconds to throttle automatic restart (you can kill the process safely now) ---" . PHP_EOL;
			sleep($spacing);
		}
		@Process::kill(Process::pid());
		exit(1);
	}

	/**
	 * @return mixed[]
	 */
	public function __debugInfo() : array{
		return [];
	}

	public function getTickSleeper() : SleeperHandler{
		return $this->tickSleeper;
	}

	private function tickProcessor() : void{
		$this->nextTick = microtime(true);

		while($this->isRunning){
			$this->tick();

			//sleeps are self-correcting - if we undersleep 1ms on this tick, we'll sleep an extra ms on the next tick
			$this->tickSleeper->sleepUntil($this->nextTick);
		}
	}

	public function addOnlinePlayer(Player $player) : void{
		foreach($this->playerList as $p){
			$p->getNetworkSession()->onPlayerAdded($player);
		}
		$rawUUID = $player->getUniqueId()->toBinary();
		$this->playerList[$rawUUID] = $player;

		if($this->sendUsageTicker > 0){
			$this->uniquePlayers[$rawUUID] = $rawUUID;
		}
	}

	public function removeOnlinePlayer(Player $player) : void{
		if(isset($this->playerList[$rawUUID = $player->getUniqueId()->toBinary()])){
			unset($this->playerList[$rawUUID]);
			foreach($this->playerList as $p){
				$p->getNetworkSession()->onPlayerRemoved($player);
			}
		}
	}

	public function sendUsage(int $type = SendUsageTask::TYPE_STATUS) : void{
		if((bool) $this->configGroup->getProperty("anonymous-statistics.enabled", true)){
			$this->asyncPool->submitTask(new SendUsageTask($this, $type, $this->uniquePlayers));
		}
		$this->uniquePlayers = [];
	}

	/**
	 * @return Language
	 */
	public function getLanguage(){
		return $this->language;
	}

	public function isLanguageForced() : bool{
		return $this->forceLanguage;
	}

	/**
	 * @return Network
	 */
	public function getNetwork(){
		return $this->network;
	}

	/**
	 * @return MemoryManager
	 */
	public function getMemoryManager(){
		return $this->memoryManager;
	}

	private function titleTick() : void{
		Timings::$titleTick->startTiming();
		$d = Process::getRealMemoryUsage();

		$u = Process::getAdvancedMemoryUsage();
		$usage = sprintf("%g/%g/%g/%g MB @ %d threads", round(($u[0] / 1024) / 1024, 2), round(($d[0] / 1024) / 1024, 2), round(($u[1] / 1024) / 1024, 2), round(($u[2] / 1024) / 1024, 2), Process::getThreadCount());

		$online = count($this->playerList);
		$connecting = $this->network->getConnectionCount() - $online;
		$bandwidthStats = $this->network->getBandwidthTracker();

		echo "\x1b]0;" . $this->getName() . " " .
			$this->getPocketFuzyVersion() .
			" | Online $online/" . $this->getMaxPlayers() .
			($connecting > 0 ? " (+$connecting connecting)" : "") .
			" | Memory " . $usage .
			" | U " . round($bandwidthStats->getSend()->getAverageBytes() / 1024, 2) .
			" D " . round($bandwidthStats->getReceive()->getAverageBytes() / 1024, 2) .
			" kB/s | TPS " . $this->getTicksPerSecondAverage() .
			" | Load " . $this->getTickUsageAverage() . "%\x07";

		Timings::$titleTick->stopTiming();
	}

	/**
	 * Tries to execute a server tick
	 */
	private function tick() : void{
		$tickTime = microtime(true);
		if(($tickTime - $this->nextTick) < -0.025){ //Allow half a tick of diff
			return;
		}

		Timings::$serverTick->startTiming();

		++$this->tickCounter;

		Timings::$scheduler->startTiming();
		$this->pluginManager->tickSchedulers($this->tickCounter);
		Timings::$scheduler->stopTiming();

		Timings::$schedulerAsync->startTiming();
		$this->asyncPool->collectTasks();
		Timings::$schedulerAsync->stopTiming();

		$this->worldManager->tick($this->tickCounter);

		Timings::$connection->startTiming();
		$this->network->tick();
		Timings::$connection->stopTiming();

		if(($this->tickCounter % 20) === 0){
			if($this->doTitleTick){
				$this->titleTick();
			}
			$this->currentTPS = 20;
			$this->currentUse = 0;

			$queryRegenerateEvent = new QueryRegenerateEvent(new QueryInfo($this));
			$queryRegenerateEvent->call();
			$this->queryInfo = $queryRegenerateEvent->getQueryInfo();

			$this->network->updateName();
			$this->network->getBandwidthTracker()->rotateAverageHistory();
		}

		if($this->sendUsageTicker > 0 and --$this->sendUsageTicker === 0){
			$this->sendUsageTicker = 6000;
			$this->sendUsage(SendUsageTask::TYPE_STATUS);
		}

		if(($this->tickCounter % 100) === 0){
			foreach($this->worldManager->getWorlds() as $world){
				$world->clearCache();
			}

			if($this->getTicksPerSecondAverage() < 12){
				$this->logger->warning($this->getLanguage()->translateString("PocketFuzy.server.tickOverload"));
			}
		}

		$this->getMemoryManager()->check();

		Timings::$serverTick->stopTiming();

		$now = microtime(true);
		$this->currentTPS = min(20, 1 / max(0.001, $now - $tickTime));
		$this->currentUse = min(1, ($now - $tickTime) / 0.05);

		TimingsHandler::tick($this->currentTPS <= $this->profilingTickRate);

		$idx = $this->tickCounter % 20;
		$this->tickAverage[$idx] = $this->currentTPS;
		$this->useAverage[$idx] = $this->currentUse;

		if(($this->nextTick - $tickTime) < -1){
			$this->nextTick = $tickTime;
		}else{
			$this->nextTick += 0.05;
		}
	}

	/**
	 * Called when something attempts to serialize the server instance.
	 *
	 * @throws \BadMethodCallException because Server instances cannot be serialized
	 */
	public function __sleep(){
		throw new \BadMethodCallException("Cannot serialize Server instance");
	}
}
