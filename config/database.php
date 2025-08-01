<?php
// config/database.php
class DatabaseConfig {
    private const HOST = 'localhost';
    private const PORT = 3306;
    private const DATABASE = 'ev_charging_system';
    private const USERNAME = 'root';
    private const PASSWORD = '';
    private const CHARSET = 'utf8mb4';
    
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . self::HOST . ";port=" . self::PORT . ";dbname=" . self::DATABASE . ";charset=" . self::CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'",
                PDO::ATTR_PERSISTENT => true
            ];
            
            $this->connection = new PDO($dsn, self::USERNAME, self::PASSWORD, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function __clone() {
        throw new Exception("Clone not allowed");
    }
    
    public function __wakeup() {
        throw new Exception("Deserializing not allowed");
    }
}

// config/ocpp_config.php
class OCPPConfig {
    public const VERSION = '1.6';
    public const WEBSOCKET_PORT = 8080;
    public const HEARTBEAT_INTERVAL = 300; // seconds
    public const CONNECTION_TIMEOUT = 30; // seconds
    public const MESSAGE_TIMEOUT = 30; // seconds
    public const MAX_RETRIES = 3;
    
    // OCPP Message Types
    public const MESSAGE_TYPES = [
        'CALL' => 2,
        'CALLRESULT' => 3,
        'CALLERROR' => 4
    ];
    
    // OCPP Actions
    public const ACTIONS = [
        // Core Profile
        'Authorize' => 'Authorize',
        'BootNotification' => 'BootNotification',
        'ChangeAvailability' => 'ChangeAvailability',
        'ChangeConfiguration' => 'ChangeConfiguration',
        'ClearCache' => 'ClearCache',
        'DataTransfer' => 'DataTransfer',
        'GetConfiguration' => 'GetConfiguration',
        'Heartbeat' => 'Heartbeat',
        'MeterValues' => 'MeterValues',
        'RemoteStartTransaction' => 'RemoteStartTransaction',
        'RemoteStopTransaction' => 'RemoteStopTransaction',
        'Reset' => 'Reset',
        'StartTransaction' => 'StartTransaction',
        'StatusNotification' => 'StatusNotification',
        'StopTransaction' => 'StopTransaction',
        'UnlockConnector' => 'UnlockConnector'
    ];
    
    // Charging Station Status
    public const STATION_STATUS = [
        'Available' => 'Available',
        'Preparing' => 'Preparing',
        'Charging' => 'Charging',
        'SuspendedEVSE' => 'SuspendedEVSE',
        'SuspendedEV' => 'SuspendedEV',
        'Finishing' => 'Finishing',
        'Reserved' => 'Reserved',
        'Unavailable' => 'Unavailable',
        'Faulted' => 'Faulted'
    ];
    
    // Stop Reasons
    public const STOP_REASONS = [
        'EmergencyStop' => 'EmergencyStop',
        'EVDisconnected' => 'EVDisconnected',
        'HardReset' => 'HardReset',
        'Local' => 'Local',
        'Other' => 'Other',
        'PowerLoss' => 'PowerLoss',
        'Reboot' => 'Reboot',
        'Remote' => 'Remote',
        'SoftReset' => 'SoftReset',
        'UnlockCommand' => 'UnlockCommand',
        'DeAuthorized' => 'DeAuthorized'
    ];
    
    public static function getEndpointUrl($stationId) {
        return "ws://localhost:" . self::WEBSOCKET_PORT . "/ocpp16/" . $stationId;
    }
    
    public static function validateMessage($message) {
        if (!is_array($message) || count($message) < 3) {
            return false;
        }
        
        $messageType = $message[0];
        if (!in_array($messageType, self::MESSAGE_TYPES)) {
            return false;
        }
        
        return true;
    }
}

// config/modbus_config.php
class ModbusConfig {
    // SUN2000 Inverter Default Settings
    public const DEFAULT_IP = '192.168.1.100';
    public const DEFAULT_PORT = 502;
    public const DEFAULT_UNIT_ID = 1;
    public const CONNECTION_TIMEOUT = 5; // seconds
    public const READ_TIMEOUT = 3; // seconds
    
    // SUN2000 Register Addresses (simplified)
    public const REGISTERS = [
        // Real-time Data
        'ACTIVE_POWER' => 32080,           // Active Power (W)
        'REACTIVE_POWER' => 32082,         // Reactive Power (Var)
        'POWER_FACTOR' => 32084,           // Power Factor
        'GRID_FREQUENCY' => 32085,         // Grid Frequency (Hz)
        'EFFICIENCY' => 32086,             // Efficiency (%)
        
        // Voltage & Current
        'INPUT_VOLTAGE' => 32016,          // Input Voltage (V)
        'INPUT_CURRENT' => 32017,          // Input Current (A)
        'GRID_A_VOLTAGE' => 32069,         // Grid A Phase Voltage (V)
        'GRID_B_VOLTAGE' => 32070,         // Grid B Phase Voltage (V)
        'GRID_C_VOLTAGE' => 32071,         // Grid C Phase Voltage (V)
        'GRID_A_CURRENT' => 32072,         // Grid A Phase Current (A)
        'GRID_B_CURRENT' => 32073,         // Grid B Phase Current (A)
        'GRID_C_CURRENT' => 32074,         // Grid C Phase Current (A)
        
        // Energy Data
        'DAILY_YIELD' => 32114,            // Daily Yield (kWh)
        'TOTAL_YIELD' => 32106,            // Total Yield (kWh)
        
        // Battery Data (if applicable)
        'BATTERY_SOC' => 37000,            // State of Charge (%)
        'BATTERY_VOLTAGE' => 37001,        // Battery Voltage (V)
        'BATTERY_CURRENT' => 37002,        // Battery Current (A)
        'BATTERY_POWER' => 37003,          // Battery Power (W)
        'BATTERY_TEMPERATURE' => 37004,    // Battery Temperature (°C)
        
        // Status & Alarms
        'DEVICE_STATUS' => 32089,          // Device Status
        'ALARM_1' => 32008,                // Alarm 1
        'ALARM_2' => 32009,                // Alarm 2
        'ALARM_3' => 32010,                // Alarm 3
        
        // Temperature
        'INTERNAL_TEMPERATURE' => 32087,   // Internal Temperature (°C)
    ];
    
    // Data Types and Scaling
    public const DATA_TYPES = [
        'ACTIVE_POWER' => ['type' => 'int32', 'scale' => 1],
        'REACTIVE_POWER' => ['type' => 'int32', 'scale' => 1],
        'POWER_FACTOR' => ['type' => 'int16', 'scale' => 1000],
        'GRID_FREQUENCY' => ['type' => 'uint16', 'scale' => 100],
        'EFFICIENCY' => ['type' => 'uint16', 'scale' => 100],
        'INPUT_VOLTAGE' => ['type' => 'uint16', 'scale' => 10],
        'INPUT_CURRENT' => ['type' => 'uint16', 'scale' => 100],
        'DAILY_YIELD' => ['type' => 'uint32', 'scale' => 100],
        'TOTAL_YIELD' => ['type' => 'uint32', 'scale' => 100],
        'BATTERY_SOC' => ['type' => 'uint16', 'scale' => 10],
        'BATTERY_VOLTAGE' => ['type' => 'uint16', 'scale' => 10],
        'BATTERY_CURRENT' => ['type' => 'int16', 'scale' => 10],
        'BATTERY_POWER' => ['type' => 'int32', 'scale' => 1],
        'BATTERY_TEMPERATURE' => ['type' => 'int16', 'scale' => 10],
        'INTERNAL_TEMPERATURE' => ['type' => 'int16', 'scale' => 10]
    ];
    
    public static function getRegisterAddress($name) {
        return self::REGISTERS[$name] ?? null;
    }
    
    public static function getDataType($name) {
        return self::DATA_TYPES[$name] ?? ['type' => 'uint16', 'scale' => 1];
    }
    
    public static function scaleValue($name, $rawValue) {
        $dataType = self::getDataType($name);
        return $rawValue / $dataType['scale'];
    }
}

// config/app_config.php
class AppConfig {
    // Application Settings
    public const APP_NAME = 'EV Charging Management System';
    public const APP_VERSION = '1.0.0';
    public const API_VERSION = 'v1';
    
    // Security Settings
    public const JWT_SECRET = 'your-secret-key-here-change-in-production';
    public const JWT_EXPIRY = 3600; // 1 hour
    public const PASSWORD_MIN_LENGTH = 8;
    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOCKOUT_DURATION = 900; // 15 minutes
    
    // Session Settings
    public const SESSION_TIMEOUT = 3600; // 1 hour
    public const REMEMBER_ME_DURATION = 2592000; // 30 days
    
    // File Upload Settings
    public const MAX_FILE_SIZE = 5242880; // 5MB
    public const ALLOWED_FILE_TYPES = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    public const UPLOAD_PATH = '../uploads/';
    
    // Email Settings
    public const SMTP_HOST = 'smtp.gmail.com';
    public const SMTP_PORT = 587;
    public const SMTP_USERNAME = 'your-email@gmail.com';
    public const SMTP_PASSWORD = 'your-app-password';
    public const FROM_EMAIL = 'noreply@evcharging.com';
    public const FROM_NAME = 'EV Charging System';
    
    // SMS Settings
    public const SMS_PROVIDER = 'twilio'; // or 'nexmo', 'aws'
    public const SMS_API_KEY = 'your-sms-api-key';
    public const SMS_API_SECRET = 'your-sms-api-secret';
    
    // Payment Settings
    public const PROMPTPAY_ID = '1234567890123'; // 13-digit ID
    public const QR_CODE_SIZE = 300;
    public const PAYMENT_TIMEOUT = 300; // 5 minutes
    
    // WebSocket Settings
    public const WEBSOCKET_HOST = '0.0.0.0';
    public const WEBSOCKET_PORT = 8080;
    public const WEBSOCKET_MAX_CONNECTIONS = 1000;
    
    // Logging Settings
    public const LOG_LEVEL = 'info'; // debug, info, warning, error
    public const LOG_PATH = '../logs/';
    public const LOG_MAX_SIZE = 10485760; // 10MB
    public const LOG_MAX_FILES = 5;
    
    // Cache Settings
    public const CACHE_DRIVER = 'file'; // file, redis, memcached
    public const CACHE_DURATION = 3600; // 1 hour
    public const CACHE_PATH = '../cache/';
    
    // Rate Limiting
    public const API_RATE_LIMIT = 100; // requests per minute
    public const API_RATE_WINDOW = 60; // seconds
    
    // Pagination
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    
    // Notification Settings
    public const ENABLE_EMAIL_NOTIFICATIONS = true;
    public const ENABLE_SMS_NOTIFICATIONS = false;
    public const ENABLE_PUSH_NOTIFICATIONS = true;
    
    // System Maintenance
    public const MAINTENANCE_MODE = false;
    public const MAINTENANCE_MESSAGE = 'System is under maintenance. Please try again later.';
    
    // Environment
    public static function isDevelopment() {
        return defined('APP_ENV') && APP_ENV === 'development';
    }
    
    public static function isProduction() {
        return defined('APP_ENV') && APP_ENV === 'production';
    }
    
    public static function getEnvironment() {
        return defined('APP_ENV') ? APP_ENV : 'development';
    }
    
    // Configuration validation
    public static function validateConfig() {
        $errors = [];
        
        if (empty(self::JWT_SECRET) || self::JWT_SECRET === 'your-secret-key-here-change-in-production') {
            $errors[] = 'JWT_SECRET must be set to a secure value';
        }
        
        if (!is_dir(self::UPLOAD_PATH)) {
            if (!mkdir(self::UPLOAD_PATH, 0755, true)) {
                $errors[] = 'Cannot create upload directory: ' . self::UPLOAD_PATH;
            }
        }
        
        if (!is_dir(self::LOG_PATH)) {
            if (!mkdir(self::LOG_PATH, 0755, true)) {
                $errors[] = 'Cannot create log directory: ' . self::LOG_PATH;
            }
        }
        
        if (!is_dir(self::CACHE_PATH)) {
            if (!mkdir(self::CACHE_PATH, 0755, true)) {
                $errors[] = 'Cannot create cache directory: ' . self::CACHE_PATH;
            }
        }
        
        return $errors;
    }
}

// Load environment-specific configuration
if (file_exists(__DIR__ . '/local_config.php')) {
    require_once __DIR__ . '/local_config.php';
}

// Validate configuration on load
$configErrors = AppConfig::validateConfig();
if (!empty($configErrors)) {
    error_log('Configuration errors: ' . implode(', ', $configErrors));
    if (AppConfig::isProduction()) {
        throw new Exception('System configuration error');
    }
}

// Set error reporting based on environment
if (AppConfig::isDevelopment()) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', 0);
}

// Set timezone
date_default_timezone_set('Asia/Bangkok');

// Define application constants
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');

// Auto-load classes
spl_autoload_register(function ($className) {
    $paths = [
        BASE_PATH . '/models/',
        BASE_PATH . '/controllers/',
        BASE_PATH . '/services/',
        BASE_PATH . '/helpers/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

?>
