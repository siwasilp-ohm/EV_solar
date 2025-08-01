<?php
// models/Database.php
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        $this->connection = DatabaseConfig::getInstance()->getConnection();
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
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
}

// models/User.php
class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function create($data) {
        $sql = "INSERT INTO users (email, password_hash, first_name, last_name, phone) 
                VALUES (:email, :password_hash, :first_name, :last_name, :phone)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $data['email'],
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':phone' => $data['phone'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    public function findById($id) {
        $sql = "SELECT * FROM users WHERE id = :id AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = :email";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }
    
    public function authenticate($email, $password) {
        $user = $this->findByEmail($email);
        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['status'] !== 'active') {
                throw new Exception('Account is not active');
            }
            return $user;
        }
        return false;
    }
    
    public function updateWalletBalance($userId, $amount, $operation = 'add') {
        try {
            $this->db->beginTransaction();
            
            $sql = "SELECT wallet_balance FROM users WHERE id = :id FOR UPDATE";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $userId]);
            $currentBalance = $stmt->fetchColumn();
            
            if ($operation === 'subtract' && $currentBalance < $amount) {
                throw new Exception('Insufficient balance');
            }
            
            $newBalance = $operation === 'add' 
                ? $currentBalance + $amount 
                : $currentBalance - $amount;
            
            $sql = "UPDATE users SET wallet_balance = :balance WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':balance' => $newBalance,
                ':id' => $userId
            ]);
            
            $this->db->commit();
            return $newBalance;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getAll($page = 1, $limit = 20, $filters = []) {
        $offset = ($page - 1) * $limit;
        $where = "WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['search'])) {
            $where .= " AND (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $sql = "SELECT id, email, first_name, last_name, phone, wallet_balance, status, created_at 
                FROM users {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function getStatistics($userId) {
        $sql = "SELECT * FROM v_user_statistics WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch();
    }
    
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['first_name', 'last_name', 'phone', 'status'])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
        
        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete($id) {
        $sql = "UPDATE users SET status = 'inactive' WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
}

// models/Employee.php
class Employee {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function create($data) {
        $sql = "INSERT INTO employees (employee_code, email, password_hash, first_name, last_name, position, role, phone) 
                VALUES (:employee_code, :email, :password_hash, :first_name, :last_name, :position, :role, :phone)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':employee_code' => $data['employee_code'],
            ':email' => $data['email'],
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':position' => $data['position'],
            ':role' => $data['role'],
            ':phone' => $data['phone'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    public function findById($id) {
        $sql = "SELECT * FROM employees WHERE id = :id AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findByEmail($email) {
        $sql = "SELECT * FROM employees WHERE email = :email";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }
    
    public function authenticate($email, $password) {
        $employee = $this->findByEmail($email);
        if ($employee && password_verify($password, $employee['password_hash'])) {
            if ($employee['status'] !== 'active') {
                throw new Exception('Account is not active');
            }
            return $employee;
        }
        return false;
    }
    
    public function getAll($page = 1, $limit = 20, $filters = []) {
        $offset = ($page - 1) * $limit;
        $where = "WHERE 1=1";
        $params = [];
        
        if (!empty($filters['role'])) {
            $where .= " AND role = :role";
            $params[':role'] = $filters['role'];
        }
        
        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        
        $sql = "SELECT id, employee_code, email, first_name, last_name, position, role, phone, status, created_at 
                FROM employees {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['first_name', 'last_name', 'position', 'role', 'phone', 'status'])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
        
        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }
        
        $sql = "UPDATE employees SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete($id) {
        $sql = "UPDATE employees SET status = 'inactive' WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
}

// models/Vehicle.php
class Vehicle {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function create($data) {
        $sql = "INSERT INTO vehicles (user_id, license_plate, brand, model, battery_capacity, max_charging_power) 
                VALUES (:user_id, :license_plate, :brand, :model, :battery_capacity, :max_charging_power)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $data['user_id'],
            ':license_plate' => $data['license_plate'],
            ':brand' => $data['brand'],
            ':model' => $data['model'],
            ':battery_capacity' => $data['battery_capacity'],
            ':max_charging_power' => $data['max_charging_power'] ?? 22.00
        ]);
        
        return $this->db->lastInsertId();
    }
    
    public function findById($id) {
        $sql = "SELECT * FROM vehicles WHERE id = :id AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findByUserId($userId) {
        $sql = "SELECT * FROM vehicles WHERE user_id = :user_id AND status = 'active' ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }
    
    public function findByLicensePlate($licensePlate) {
        $sql = "SELECT * FROM vehicles WHERE license_plate = :license_plate AND status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':license_plate' => $licensePlate]);
        return $stmt->fetch();
    }
    
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['license_plate', 'brand', 'model', 'battery_capacity', 'max_charging_power', 'status'])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
        
        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }
        
        $sql = "UPDATE vehicles SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function delete($id) {
        $sql = "UPDATE vehicles SET status = 'inactive' WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
}

// models/ChargingStation.php
class ChargingStation {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function create($data) {
        $sql = "INSERT INTO charging_stations (station_code, name, location_name, latitude, longitude, max_power, connector_type, ocpp_endpoint, modbus_address) 
                VALUES (:station_code, :name, :location_name, :latitude, :longitude, :max_power, :connector_type, :ocpp_endpoint, :modbus_address)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':station_code' => $data['station_code'],
            ':name' => $data['name'],
            ':location_name' => $data['location_name'],
            ':latitude' => $data['latitude'] ?? null,
            ':longitude' => $data['longitude'] ?? null,
            ':max_power' => $data['max_power'],
            ':connector_type' => $data['connector_type'],
            ':ocpp_endpoint' => $data['ocpp_endpoint'] ?? null,
            ':modbus_address' => $data['modbus_address'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    public function findById($id) {
        $sql = "SELECT * FROM charging_stations WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findByStationCode($stationCode) {
        $sql = "SELECT * FROM charging_stations WHERE station_code = :station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':station_code' => $stationCode]);
        return $stmt->fetch();
    }
    
    public function getAll($filters = []) {
        $where = "WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $where .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['connector_type'])) {
            $where .= " AND connector_type = :connector_type";
            $params[':connector_type'] = $filters['connector_type'];
        }
        
        $sql = "SELECT * FROM charging_stations {$where} ORDER BY station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    public function getAvailableStations() {
        $sql = "SELECT * FROM v_station_status WHERE current_status = 'available' ORDER BY station_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function updateStatus($id, $status) {
        $sql = "UPDATE charging_stations SET status = :status, last_heartbeat = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':id' => $id
        ]);
    }
    
    public function updateHeartbeat($stationCode) {
        $sql = "UPDATE charging_stations SET last_heartbeat = NOW() WHERE station_code = :station_code";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':station_code' => $stationCode]);
    }
    
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['name', 'location_name', 'latitude', 'longitude', 'max_power', 'connector_type', 'ocpp_endpoint', 'modbus_address', 'status'])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }
        
        if (empty($fields)) {
            throw new Exception('No valid fields to update');
        }
        
        $sql = "UPDATE charging_stations SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    public function getStationStatistics() {
        $sql = "SELECT 
                    COUNT(*) as total_stations,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_stations,
                    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_stations,
                    SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_stations,
                    SUM(CASE WHEN status = 'faulted' THEN 1 ELSE 0 END) as faulted_stations,
                    AVG(max_power) as average_power
                FROM charging_stations";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetch();
    }
}

// models/Transaction.php
class Transaction {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function startChargingSession($userId, $vehicleId, $stationId, $ocppTransactionId = null) {
        try {
            $this->db->beginTransaction();
            
            // Generate transaction code
            $sql = "SELECT COUNT(*) + 1 as next_number FROM charging_transactions WHERE DATE(created_at) = CURDATE()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $nextNumber = $stmt->fetchColumn();
            
            $transactionCode = 'TX' . date('Ymd') . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            
            // Insert charging transaction
            $sql = "INSERT INTO charging_transactions (transaction_code, user_id, vehicle_id, station_id, ocpp_transaction_id, start_time, status) 
                    VALUES (:transaction_code, :user_id, :vehicle_id, :station_id, :ocpp_transaction_id, NOW(), 'preparing')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':transaction_code' => $transactionCode,
                ':user_id' => $userId,
                ':vehicle_id' => $vehicleId,
                ':station_id' => $stationId,
                ':ocpp_transaction_id' => $ocppTransactionId
            ]);
            
            $transactionId = $this->db->lastInsertId();
            
            // Update station status
            $sql = "UPDATE charging_stations SET status = 'occupied' WHERE id = :station_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':station_id' => $stationId]);
            
            $this->db->commit();
            
            return [
                'id' => $transactionId,
                'transaction_code' => $transactionCode
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function completeChargingSession($transactionCode, $endMeterValue, $stopReason = 'user') {
        try {
            $this->db->beginTransaction();
            
            // Get transaction details
            $sql = "SELECT ct.*, cs.id as station_id 
                    FROM charging_transactions ct 
                    JOIN charging_stations cs ON ct.station_id = cs.id 
                    WHERE ct.transaction_code = :transaction_code";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':transaction_code' => $transactionCode]);
            $transaction = $stmt->fetch();
            
            if (!$transaction) {
                throw new Exception('Transaction not found');
            }
            
            // Calculate energy delivered
            $energyDelivered = $endMeterValue - $transaction['start_meter_value'];
            
            // Get current pricing
            $sql = "SELECT price_per_kwh FROM electricity_pricing WHERE source_type = 'pea' AND status = 'active' ORDER BY effective_date DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $peaPrice = $stmt->fetchColumn() ?: 4.50;
            
            $sql = "SELECT price_per_kwh FROM electricity_pricing WHERE source_type = 'solar' AND status = 'active' ORDER BY effective_date DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $solarPrice = $stmt->fetchColumn() ?: 3.80;
            
            // Calculate costs (50% solar, 50% PEA for demo)
            $solarCost = $energyDelivered * 0.5 * $solarPrice;
            $peaCost = $energyDelivered * 0.5 * $peaPrice;
            $totalCost = $solarCost + $peaCost;
            
            // Update transaction
            $sql = "UPDATE charging_transactions SET 
                        end_time = NOW(),
                        end_meter_value = :end_meter_value,
                        energy_delivered = :energy_delivered,
                        total_cost = :total_cost,
                        solar_cost = :solar_cost,
                        pea_cost = :pea_cost,
                        status = 'completed',
                        stop_reason = :stop_reason
                    WHERE transaction_code = :transaction_code";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':end_meter_value' => $endMeterValue,
                ':energy_delivered' => $energyDelivered,
                ':total_cost' => $totalCost,
                ':solar_cost' => $solarCost,
                ':pea_cost' => $peaCost,
                ':stop_reason' => $stopReason,
                ':transaction_code' => $transactionCode
            ]);
            
            // Process payment from wallet
            $userModel = new User();
            $userModel->updateWalletBalance($transaction['user_id'], $totalCost, 'subtract');
            
            // Create payment record
            $sql = "INSERT INTO payment_transactions (user_id, charging_transaction_id, payment_type, payment_method, amount, status, processed_at) 
                    VALUES (:user_id, :charging_transaction_id, 'charging', 'wallet', :amount, 'completed', NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':user_id' => $transaction['user_id'],
                ':charging_transaction_id' => $transaction['id'],
                ':amount' => $totalCost
            ]);
            
            // Update station status
            $sql = "UPDATE charging_stations SET status = 'available' WHERE id = :station_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':station_id' => $transaction['station_id']]);
            
            $this->db->commit();
            
            return [
                'energy_delivered' => $energyDelivered,
                'total_cost' => $totalCost,
                'solar_cost' => $solarCost,
                'pea_cost' => $peaCost
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function findById($id) {
        $sql = "SELECT ct.*, u.first_name, u.last_name, v.license_plate, cs.station_code, cs.name as station_name
                FROM charging_transactions ct
                JOIN users u ON ct.user_id = u.id
                JOIN vehicles v ON ct.vehicle_id = v.id  
                JOIN charging_stations cs ON ct.station_id = cs.id
                WHERE ct.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findByTransactionCode($transactionCode) {
        $sql = "SELECT * FROM charging_transactions WHERE transaction_code = :transaction_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':transaction_code' => $transactionCode]);
        return $stmt->fetch();
    }
    
    public function getActiveTransactionByUser($userId) {
        $sql = "SELECT ct.*, cs.station_code, cs.name as station_name 
                FROM charging_transactions ct
                JOIN charging_stations cs ON ct.station_id = cs.id
                WHERE ct.user_id = :user_id AND ct.status IN ('preparing', 'charging', 'suspended')";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch();
    }
    
    public function getUserTransactions($userId, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT ct.*, cs.station_code, cs.name as station_name, v.license_plate
                FROM charging_transactions ct
                JOIN charging_stations cs ON ct.station_id = cs.id
                JOIN vehicles v ON ct.vehicle_id = v.id
                WHERE ct.user_id = :user_id 
                ORDER BY ct.start_time DESC 
                LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }
    
    public function getTransactionLogs($page = 1, $limit = 50, $filters = []) {
        $offset = ($page - 1) * $limit;
        $where = "WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $where .= " AND ct.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['station_id'])) {
            $where .= " AND ct.station_id = :station_id";
            $params[':station_id'] = $filters['station_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where .= " AND ct.start_time >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where .= " AND ct.start_time <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        $sql = "SELECT ct.*, u.first_name, u.last_name, u.email, 
                       v.license_plate, cs.station_code, cs.name as station_name
                FROM charging_transactions ct
                JOIN users u ON ct.user_id = u.id
                JOIN vehicles v ON ct.vehicle_id = v.id
                JOIN charging_stations cs ON ct.station_id = cs.id
                {$where}
                ORDER BY ct.start_time DESC 
                LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateStatus($transactionCode, $status) {
        $sql = "UPDATE charging_transactions SET status = :status WHERE transaction_code = :transaction_code";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':transaction_code' => $transactionCode
        ]);
