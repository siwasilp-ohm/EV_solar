<?php
// services/OCPPService.php
require_once __DIR__ . '/../vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\WebSocket\WsServer;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;

class OCPPService implements MessageComponentInterface {
    private $clients;
    private $stations;
    private $db;
    private $logger;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->stations = [];
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new SystemLog();
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        
        // Extract station ID from URL path
        $path = $conn->httpRequest->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        
        if (count($pathParts) >= 2 && $pathParts[0] === 'ocpp16') {
            $stationCode = $pathParts[1];
            $conn->stationCode = $stationCode;
            $this->stations[$stationCode] = $conn;
            
            $this->logger->info('ocpp', "Station {$stationCode} connected", [
                'station_code' => $stationCode,
                'remote_address' => $conn->remoteAddress
            ]);
            
            // Update station heartbeat
            $this->updateStationHeartbeat($stationCode);
        }
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $data = json_decode($msg, true);
            
            if (!$this->validateOCPPMessage($data)) {
                $this->sendError($from, null, 'FormatError', 'Invalid message format');
                return;
            }
            
            $messageType = $data[0];
            $messageId = $data[1];
            
            switch ($messageType) {
                case OCPPConfig::MESSAGE_TYPES['CALL']:
                    $this->handleCall($from, $messageId, $data[2], $data[3] ?? []);
                    break;
                    
                case OCPPConfig::MESSAGE_TYPES['CALLRESULT']:
                    $this->handleCallResult($from, $messageId, $data[2]);
                    break;
                    
                case OCPPConfig::MESSAGE_TYPES['CALLERROR']:
                    $this->handleCallError($from, $messageId, $data[2], $data[3], $data[4] ?? '');
                    break;
            }
            
            // Log message
            $this->logOCPPMessage($from->stationCode ?? 'unknown', $messageType, $data, 'incoming');
            
        } catch (Exception $e) {
            $this->logger->error('ocpp', 'Error processing OCPP message', [
                'error' => $e->getMessage(),
                'message' => $msg
            ]);
            
            $this->sendError($from, null, 'InternalError', 'Internal server error');
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        
        if (isset($conn->stationCode)) {
            $stationCode = $conn->stationCode;
            unset($this->stations[$stationCode]);
            
            $this->logger->info('ocpp', "Station {$stationCode} disconnected");
            
            // Update station status to offline
            $this->updateStationStatus($stationCode, 'offline');
        }
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->logger->error('ocpp', 'Connection error', [
            'error' => $e->getMessage(),
            'station_code' => $conn->stationCode ?? 'unknown'
        ]);
        
        $conn->close();
    }
    
    private function handleCall($conn, $messageId, $action, $payload) {
        $stationCode = $conn->stationCode ?? 'unknown';
        
        switch ($action) {
            case 'BootNotification':
                $this->handleBootNotification($conn, $messageId, $payload);
                break;
                
            case 'Heartbeat':
                $this->handleHeartbeat($conn, $messageId);
                break;
                
            case 'StatusNotification':
                $this->handleStatusNotification($conn, $messageId, $payload);
                break;
                
            case 'StartTransaction':
                $this->handleStartTransaction($conn, $messageId, $payload);
                break;
                
            case 'StopTransaction':
                $this->handleStopTransaction($conn, $messageId, $payload);
                break;
                
            case 'MeterValues':
                $this->handleMeterValues($conn, $messageId, $payload);
                break;
                
            case 'Authorize':
                $this->handleAuthorize($conn, $messageId, $payload);
                break;
                
            default:
                $this->sendError($conn, $messageId, 'NotSupported', "Action {$action} not supported");
        }
    }
    
    private function handleBootNotification($conn, $messageId, $payload) {
        $stationCode = $conn->stationCode;
        
        // Update station information
        $this->updateStationInfo($stationCode, $payload);
        
        $response = [
            'status' => 'Accepted',
            'currentTime' => date('c'),
            'interval' => OCPPConfig::HEARTBEAT_INTERVAL
        ];
        
        $this->sendCallResult($conn, $messageId, $response);
        
        $this->logger->info('ocpp', "Boot notification from station {$stationCode}", $payload);
    }
    
    private function handleHeartbeat($conn, $messageId) {
        $stationCode = $conn->stationCode;
        
        $this->updateStationHeartbeat($stationCode);
        
        $response = [
            'currentTime' => date('c')
        ];
        
        $this->sendCallResult($conn, $messageId, $response);
    }
    
    private function handleStatusNotification($conn, $messageId, $payload) {
        $stationCode = $conn->stationCode;
        $status = $payload['status'] ?? 'Unknown';
        
        // Map OCPP status to our internal status
        $internalStatus = $this->mapOCPPStatus($status);
        $this->updateStationStatus($stationCode, $internalStatus);
        
        $response = [];
        $this->sendCallResult($conn, $messageId, $response);
        
        $this->logger->info('ocpp', "Status notification from station {$stationCode}", [
            'status' => $status,
            'internal_status' => $internalStatus
        ]);
        
        // Broadcast status update via WebSocket
        $this->broadcastStationStatus($stationCode, $internalStatus);
    }
    
    private function handleStartTransaction($conn, $messageId, $payload) {
        $stationCode = $conn->stationCode;
        $idTag = $payload['idTag'] ?? '';
        $meterStart = $payload['meterStart'] ?? 0;
        $timestamp = $payload['timestamp'] ?? date('c');
        
        try {
            // Find user by ID tag (RFID or user ID)
            $userModel = new User();
            $user = $userModel->findById($idTag) ?: $userModel->findByEmail($idTag);
            
            if (!$user) {
                $response = [
                    'idTagInfo' => [
                        'status' => 'Invalid'
                    ]
                ];
            } else {
                // Start charging transaction
                $transactionModel = new Transaction();
                $chargingStation = new ChargingStation();
                $station = $chargingStation->findByStationCode($stationCode);
                
                if (!$station) {
                    throw new Exception("Station not found: {$stationCode}");
                }
                
                // Get user's default vehicle
                $vehicleModel = new Vehicle();
                $vehicles = $vehicleModel->findByUserId($user['id']);
                $vehicle = $vehicles[0] ?? null;
                
                if (!$vehicle) {
                    throw new Exception("No vehicle registered for user");
                }
                
                $transactionResult = $transactionModel->startChargingSession(
                    $user['id'], 
                    $vehicle['id'], 
                    $station['id'],
                    null // Will be set after OCPP response
                );
                
                // Generate OCPP transaction ID
                $ocppTransactionId = time() . rand(1000, 9999);
                
                // Update transaction with OCPP ID
                $sql = "UPDATE charging_transactions SET ocpp_transaction_id = :ocpp_id, start_meter_value = :meter_start WHERE transaction_code = :code";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':ocpp_id' => $ocppTransactionId,
                    ':meter_start' => $meterStart / 1000, // Convert Wh to kWh
                    ':code' => $transactionResult['transaction_code']
                ]);
                
                $response = [
                    'idTagInfo' => [
                        'status' => 'Accepted'
                    ],
                    'transactionId' => $ocppTransactionId
                ];
                
                $this->logger->info('ocpp', "Transaction started", [
                    'station_code' => $stationCode,
                    'user_id' => $user['id'],
                    'transaction_code' => $transactionResult['transaction_code'],
                    'ocpp_transaction_id' => $ocppTransactionId
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('ocpp', "Error starting transaction", [
                'station_code' => $stationCode,
                'error' => $e->getMessage()
            ]);
            
            $response = [
                'idTagInfo' => [
                    'status' => 'Blocked'
                ]
            ];
        }
        
        $this->sendCallResult($conn, $messageId, $response);
    }
    
    private function handleStopTransaction($conn, $messageId, $payload) {
        $stationCode = $conn->stationCode;
        $transactionId = $payload['transactionId'] ?? 0;
        $meterStop = $payload['meterStop'] ?? 0;
        $timestamp = $payload['timestamp'] ?? date('c');
        $reason = $payload['reason'] ?? 'Remote';
        
        try {
            // Find transaction by OCPP transaction ID
            $sql = "SELECT * FROM charging_transactions WHERE ocpp_transaction_id = :ocpp_id AND status IN ('preparing', 'charging', 'suspended')";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':ocpp_id' => $transactionId]);
            $transaction = $stmt->fetch();
            
            if ($transaction) {
                $transactionModel = new Transaction();
                $result = $transactionModel->completeChargingSession(
                    $transaction['transaction_code'],
                    $meterStop / 1000, // Convert Wh to kWh
                    strtolower($reason)
                );
                
                $response = [
                    'idTagInfo' => [
                        'status' => 'Accepted'
                    ]
                ];
                
                $this->logger->info('ocpp', "Transaction stopped", [
                    'station_code' => $stationCode,
                    'transaction_code' => $transaction['transaction_code'],
                    'energy_delivered' => $result['energy_delivered'],
                    'total_cost' => $result['total_cost']
                ]);
                
                // Broadcast transaction completion
                $this->broadcastTransactionComplete($transaction['transaction_code'], $result);
            } else {
                $response = [
                    'idTagInfo' => [
                        'status' => 'Invalid'
                    ]
                ];
            }
        } catch (Exception $e) {
            $this->logger->error('ocpp', "Error stopping transaction", [
                'station_code' => $stationCode,
                'ocpp_transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            
            $response = [
                'idTagInfo' => [
                    'status' => 'Blocked'
                ]
            ];
        }
        
        $this->sendCallResult($conn, $messageId, $response);
    }
    
    private function handleMeterValues($conn, $messageId, $payload) {
        $stationCode = $conn->stationCode;
        $transactionId = $payload['transactionId'] ?? 0;
        $meterValues = $payload['meterValue'] ?? [];
        
        foreach ($meterValues as $meterValue) {
            $timestamp = $meterValue['timestamp'] ?? date('c');
            $sampledValues = $meterValue['sampledValue'] ?? [];
            
            foreach ($sampledValues as $sample) {
                $value = $sample['value'] ?? 0;
                $measurand = $sample['measurand'] ?? 'Energy.Active.Import.Register';
                $unit = $sample['unit'] ?? 'Wh';
                
                // Store meter values for real-time monitoring
                $this->storeMeterValue($stationCode, $transactionId, $measurand, $value, $unit, $timestamp);
            }
        }
        
        $response = [];
        $this->sendCallResult($conn, $messageId, $response);
        
        // Broadcast real-time data
        $this->broadcastMeterValues($stationCode, $transactionId, $meterValues);
    }
    
    private function handleAuthorize($conn, $messageId, $payload) {
        $idTag = $payload['idTag'] ?? '';
        
        // Check if user exists and is active
        $userModel = new User();
        $user = $userModel->findById($idTag) ?: $userModel->findByEmail($idTag);
        
        if ($user && $user['status'] === 'active' && $user['wallet_balance'] > 0) {
            $response = [
                'idTagInfo' => [
                    'status' => 'Accepted'
                ]
            ];
        } else {
            $response = [
                'idTagInfo' => [
                    'status' => 'Invalid'
                ]
            ];
        }
        
        $this->sendCallResult($conn, $messageId, $response);
    }
    
    // Remote commands
    public function remoteStartTransaction($stationCode, $idTag, $connectorId = 1) {
        if (!isset($this->stations[$stationCode])) {
            throw new Exception("Station {$stationCode} not connected");
        }
        
        $conn = $this->stations[$stationCode];
        $messageId = $this->generateMessageId();
        
        $payload = [
            'connectorId' => $connectorId,
            'idTag' => $idTag
        ];
        
        $this->sendCall($conn, $messageId, 'RemoteStartTransaction', $payload);
        
        return $messageId;
    }
    
    public function remoteStopTransaction($stationCode, $transactionId) {
        if (!isset($this->stations[$stationCode])) {
            throw new Exception("Station {$stationCode} not connected");
        }
        
        $conn = $this->stations[$stationCode];
        $messageId = $this->generateMessageId();
        
        $payload = [
            'transactionId' => $transactionId
        ];
        
        $this->sendCall($conn, $messageId, 'RemoteStopTransaction', $payload);
        
        return $messageId;
    }
    
    public function resetStation($stationCode, $type = 'Soft') {
        if (!isset($this->stations[$stationCode])) {
            throw new Exception("Station {$stationCode} not connected");
        }
        
        $conn = $this->stations[$stationCode];
        $messageId = $this->generateMessageId();
        
        $payload = [
            'type' => $type
        ];
        
        $this->sendCall($conn, $messageId, 'Reset', $payload);
        
        return $messageId;
    }
    
    public function unlockConnector($stationCode, $connectorId = 1) {
        if (!isset($this->stations[$stationCode])) {
            throw new Exception("Station {$stationCode} not connected");
        }
        
        $conn = $this->stations[$stationCode];
        $messageId = $this->generateMessageId();
        
        $payload = [
            'connectorId' => $connectorId
        ];
        
        $this->sendCall($conn, $messageId, 'UnlockConnector', $payload);
        
        return $messageId;
    }
    
    // Helper methods
    private function sendCall($conn, $messageId, $action, $payload) {
        $message = [
            OCPPConfig::MESSAGE_TYPES['CALL'],
            $messageId,
            $action,
            $payload
        ];
        
        $conn->send(json_encode($message));
        $this->logOCPPMessage($conn->stationCode, OCPPConfig::MESSAGE_TYPES['CALL'], $message, 'outgoing');
    }
    
    private function sendCallResult($conn, $messageId, $payload) {
        $message = [
            OCPPConfig::MESSAGE_TYPES['CALLRESULT'],
            $messageId,
            $payload
        ];
        
        $conn->send(json_encode($message));
        $this->logOCPPMessage($conn->stationCode, OCPPConfig::MESSAGE_TYPES['CALLRESULT'], $message, 'outgoing');
    }
    
    private function sendError($conn, $messageId, $errorCode, $errorDescription, $errorDetails = []) {
        $message = [
            OCPPConfig::MESSAGE_TYPES['CALLERROR'],
            $messageId,
            $errorCode,
            $errorDescription,
            $errorDetails
        ];
        
        $conn->send(json_encode($message));
        $this->logOCPPMessage($conn->stationCode ?? 'unknown', OCPPConfig::MESSAGE_TYPES['CALLERROR'], $message, 'outgoing');
    }
    
    private function validateOCPPMessage($data) {
        return OCPPConfig::validateMessage($data);
    }
    
    private function generateMessageId() {
        return uniqid('msg_', true);
    }
    
    private function mapOCPPStatus($ocppStatus) {
        $statusMap = [
            'Available' => 'available',
            'Preparing' => 'available',
            'Charging' => 'occupied',
            'SuspendedEVSE' => 'occupied',
            'SuspendedEV' => 'occupied',
            'Finishing' => 'occupied',
            'Reserved' => 'occupied',
            'Unavailable' => 'offline',
            'Faulted' => 'faulted'
        ];
        
        return $statusMap[$ocppStatus] ?? 'offline';
    }
    
    private function updateStationHeartbeat($stationCode) {
        $sql = "UPDATE charging_stations SET last_heartbeat = NOW() WHERE station_code = :station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':station_code' => $stationCode]);
    }
    
    private function updateStationStatus($stationCode, $status) {
        $sql = "UPDATE charging_stations SET status = :status, last_heartbeat = NOW() WHERE station_code = :station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':station_code' => $stationCode
        ]);
    }
    
    private function updateStationInfo($stationCode, $info) {
        // Update station information from boot notification
        $sql = "UPDATE charging_stations SET last_heartbeat = NOW() WHERE station_code = :station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':station_code' => $stationCode]);
    }
    
    private function storeMeterValue($stationCode, $transactionId, $measurand, $value, $unit, $timestamp) {
        // Store meter values for real-time monitoring
        // This could be stored in a separate table or cache for performance
        
        $cacheKey = "meter_values:{$stationCode}:{$transactionId}";
        $data = [
            'measurand' => $measurand,
            'value' => $value,
            'unit' => $unit,
            'timestamp' => $timestamp
        ];
        
        // In production, use Redis or similar cache
        // For now, we'll just log it
        $this->logger->debug('occp_meter', "Meter value", [
            'station_code' => $stationCode,
            'transaction_id' => $transactionId,
            'data' => $data
        ]);
    }
    
    private function logOCPPMessage($stationCode, $messageType, $data, $direction) {
        // Find station ID
        $sql = "SELECT id FROM charging_stations WHERE station_code = :station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':station_code' => $stationCode]);
        $stationId = $stmt->fetchColumn();
        
        if ($stationId) {
            $action = '';
            if (isset($data[2]) && is_string($data[2])) {
                $action = $data[2];
            }
            
            $sql = "INSERT INTO ocpp_messages (station_id, message_type, action, message_id, payload, direction) 
                    VALUES (:station_id, :message_type, :action, :message_id, :payload, :direction)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':station_id' => $stationId,
                ':message_type' => array_search($messageType, OCPPConfig::MESSAGE_TYPES) ?: 'unknown',
                ':action' => $action,
                ':message_id' => $data[1] ?? '',
                ':payload' => json_encode($data),
                ':direction' => $direction
            ]);
        }
    }
    
    private function broadcastStationStatus($stationCode, $status) {
        // Broadcast to WebSocket clients (would be implemented in WebSocketServer)
        $message = [
            'type' => 'station_status',
            'station_code' => $stationCode,
            'status' => $status,
            'timestamp' => date('c')
        ];
        
        // This would integrate with the main WebSocket server
        $this->logger->debug('broadcast', 'Station status update', $message);
    }
    
    private function broadcastTransactionComplete($transactionCode, $result) {
        $message = [
            'type' => 'transaction_complete',
            'transaction_code' => $transactionCode,
            'result' => $result,
            'timestamp' => date('c')
        ];
        
        $this->logger->debug('broadcast', 'Transaction complete', $message);
    }
    
    private function broadcastMeterValues($stationCode, $transactionId, $meterValues) {
        $message = [
            'type' => 'meter_values',
            'station_code' => $stationCode,
            'transaction_id' => $transactionId,
            'meter_values' => $meterValues,
            'timestamp' => date('c')
        ];
        
        $this->logger->debug('broadcast', 'Meter values update', $message);
    }
}

// services/ModbusService.php
class ModbusService {
    private $connection;
    private $logger;
    private $config;
    
    public function __construct($ip = null, $port = null, $unitId = null) {
        $this->config = [
            'ip' => $ip ?: ModbusConfig::DEFAULT_IP,
            'port' => $port ?: ModbusConfig::DEFAULT_PORT,
            'unit_id' => $unitId ?: ModbusConfig::DEFAULT_UNIT_ID
        ];
        
        $this->logger = new SystemLog();
    }
    
    public function connect() {
        try {
            $this->connection = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            
            if (!$this->connection) {
                throw new Exception("Failed to create socket");
            }
            
            socket_set_option($this->connection, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => ModbusConfig::CONNECTION_TIMEOUT,
                'usec' => 0
            ]);
            
            socket_set_option($this->connection, SOL_SOCKET, SO_SNDTIMEO, [
                'sec' => ModbusConfig::CONNECTION_TIMEOUT,
                'usec' => 0
            ]);
            
            $result = socket_connect($this->connection, $this->config['ip'], $this->config['port']);
            
            if (!$result) {
                throw new Exception("Failed to connect to {$this->config['ip']}:{$this->config['port']}");
            }
            
            $this->logger->info('modbus', 'Connected to inverter', $this->config);
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('modbus', 'Connection failed', [
                'error' => $e->getMessage(),
                'config' => $this->config
            ]);
            return false;
        }
    }
    
    public function disconnect() {
        if ($this->connection) {
            socket_close($this->connection);
            $this->connection = null;
            $this->logger->info('modbus', 'Disconnected from inverter');
        }
    }
    
    public function readHoldingRegisters($startAddress, $quantity = 1) {
        if (!$this->connection) {
            throw new Exception("Not connected to Modbus device");
        }
        
        try {
            // Build Modbus TCP frame
            $transactionId = rand(1, 65535);
            $protocolId = 0;
            $unitId = $this->config['unit_id'];
            $functionCode = 3; // Read holding registers
            
            // PDU (Protocol Data Unit)
            $pdu = pack('CCnn', $functionCode, $unitId, $startAddress, $quantity);
            $length = strlen($pdu);
            
            // MBAP Header (Modbus Application Protocol)
            $mbap = pack('nnnC', $transactionId, $protocolId, $length + 1, $unitId);
            
            // Complete frame
            $frame = $mbap . chr($functionCode) . pack('nn', $startAddress, $quantity);
            
            // Send request
            $bytesSent = socket_write($this->connection, $frame, strlen($frame));
            if ($bytesSent === false) {
                throw new Exception("Failed to send Modbus request");
            }
            
            // Read response
            $response = socket_read($this->connection, 1024);
            if ($response === false) {
                throw new Exception("Failed to read Modbus response");
            }
            
            // Parse response
            return $this->parseHoldingRegistersResponse($response, $quantity);
            
        } catch (Exception $e) {
            $this->logger->error('modbus', 'Read failed', [
                'error' => $e->getMessage(),
                'address' => $startAddress,
                'quantity' => $quantity
            ]);
            throw $e;
        }
    }
    
    private function parseHoldingRegistersResponse($response, $expectedQuantity) {
        if (strlen($response) < 9) {
            throw new Exception("Invalid response length");
        }
        
        // Skip MBAP header (7 bytes) and function code (1 byte)
        $dataLength = ord($response[8]);
        $expectedDataLength = $expectedQuantity * 2;
        
        if ($dataLength !== $expectedDataLength) {
            throw new Exception("Unexpected data length in response");
        }
        
        $data = substr($response, 9, $dataLength);
        $values = [];
        
        for ($i = 0; $i < $expectedQuantity; $i++) {
            $values[] = unpack('n', substr($data, $i * 2, 2))[1];
        }
        
        return $values;
    }
    
    public function readSolarData() {
        try {
            $data = [];
            
            // Read basic power data
            $activePower = $this->readRegister('ACTIVE_POWER');
            $data['active_power'] = $activePower / 1000; // Convert W to kW
            
            $reactivePower = $this->readRegister('REACTIVE_POWER');
            $data['reactive_power'] = $reactivePower / 1000;
            
            $efficiency = $this->readRegister('EFFICIENCY');
            $data['efficiency'] = ModbusConfig::scaleValue('EFFICIENCY', $efficiency);
            
            // Read voltage and current
            $inputVoltage = $this->readRegister('INPUT_VOLTAGE');
            $data['input_voltage'] = ModbusConfig::scaleValue('INPUT_VOLTAGE', $inputVoltage);
            
            $inputCurrent = $this->readRegister('INPUT_CURRENT');
            $data['input_current'] = ModbusConfig::scaleValue('INPUT_CURRENT', $inputCurrent);
            
            // Read energy data
            $dailyYield = $this->readRegister('DAILY_YIELD');
            $data['daily_yield'] = ModbusConfig::scaleValue('DAILY_YIELD', $dailyYield);
            
            $totalYield = $this->readRegister('TOTAL_YIELD');
            $data['total_yield'] = ModbusConfig::scaleValue('TOTAL_YIELD', $totalYield);
            
            // Read temperature
            $temperature = $this->readRegister('INTERNAL_TEMPERATURE');
            $data['temperature'] = ModbusConfig::scaleValue('INTERNAL_TEMPERATURE', $temperature);
            
            // Try to read battery data (may not be available on all inverters)
            try {
                $batterySoc = $this->readRegister('BATTERY_SOC');
                $data['battery_soc'] = ModbusConfig::scaleValue('BATTERY_SOC', $batterySoc);
                
                $batteryVoltage = $this->readRegister('BATTERY_VOLTAGE');
                $data['battery_voltage'] = ModbusConfig::scaleValue('BATTERY_VOLTAGE', $batteryVoltage);
                
                $batteryCurrent = $this->readRegister('BATTERY_CURRENT');
                $data['battery_current'] = ModbusConfig::scaleValue('BATTERY_CURRENT', $batteryCurrent);
                
                $batteryPower = $this->readRegister('BATTERY_POWER');
                $data['battery_power'] = $batteryPower / 1000; // Convert W to kW
                
                $batteryTemperature = $this->readRegister('BATTERY_TEMPERATURE');
                $data['battery_temperature'] = ModbusConfig::scaleValue('BATTERY_TEMPERATURE', $batteryTemperature);
            } catch (Exception $e) {
                // Battery data not available, continue without it
                $data['battery_soc'] = 0;
                $data['battery_voltage'] = 0;
                $data['battery_current'] = 0;
                $data['battery_power'] = 0;
                $data['battery_temperature'] = 0;
            }
            
            $data['timestamp'] = date('Y-m-d H:i:s');
            
            return $data;
            
        } catch (Exception $e) {
            $this->logger->error('modbus', 'Failed to read solar data', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    private function readRegister($registerName) {
        $address = ModbusConfig::getRegisterAddress($registerName);
        if ($address === null) {
            throw new Exception("Unknown register: {$registerName}");
        }
        
        $dataType = ModbusConfig::getDataType($registerName);
        $quantity = $dataType['type'] === 'int32' || $dataType['type'] === 'uint32' ? 2 : 1;
        
        $values = $this->readHoldingRegisters($address, $quantity);
        
        if ($quantity === 2) {
            // Combine two 16-bit registers into one 32-bit value
            return ($values[0] << 16) | $values[1];
        } else {
            return $values[0];
        }
    }
    
    public function testConnection() {
        try {
            $connected = $this->connect();
            if (!$connected) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to inverter'
                ];
            }
            
            // Try to read a basic register
            $deviceStatus = $this->readRegister('DEVICE_STATUS');
            
            $this->disconnect();
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'device_status' => $deviceStatus
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function getInverterInfo() {
        try {
            if (!$this->connect()) {
                throw new Exception("Failed to connect to inverter");
            }
            
            $info = [
                'model' => 'SUN2000',
                'ip_address' => $this->config['ip'],
                'port' => $this->config['port'],
                'unit_id' => $this->config['unit_id'],
                'status' => 'online',
                'last_update' => date('Y-m-d H:i:s')
            ];
            
            // Read device status
            try {
                $deviceStatus = $this->readRegister('DEVICE_STATUS');
                $info['device_status'] = $deviceStatus;
                $info['status_text'] = $this->getStatusText($deviceStatus);
            } catch (Exception $e) {
                $info['status'] = 'error';
                $info['error'] = $e->getMessage();
            }
            
            $this->disconnect();
            
            return $info;
            
        } catch (Exception $e) {
            return [
                'model' => 'SUN2000',
                'ip_address' => $this->config['ip'],
                'port' => $this->config['port'],
                'unit_id' => $this->config['unit_id'],
                'status' => 'offline',
                'error' => $e->getMessage(),
                'last_update' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    private function getStatusText($statusCode) {
        $statusCodes = [
            0x0000 => 'Standby',
            0x0001 => 'Grid-Connected',
            0x0002 => 'Grid-Connected normally',
            0x0003 => 'Grid connection with derating due to power rationing',
            0x0004 => 'Grid connection with derating due to internal causes of the solar inverter',
            0x0005 => 'Normal stop',
            0x0006 => 'Stop due to faults',
            0x0007 => 'Stop due to power rationing',
            0x0008 => 'Shutdown',
            0x0009 => 'Spot check'
        ];
        
        return $statusCodes[$statusCode] ?? "Unknown status ({$statusCode})";
    }
}

?>$this->handle
