<?php

require "config.php";

// Debug configuration
define('CSV_DEBUG_ENABLED', true); // Set to false to disable CSV debug logging

// JWT Helper Functions
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function generateJWT($username) {
    $header = json_encode(['typ' => 'JWT', 'alg' => JWT_ALGORITHM]);
    $payload = json_encode([
        'sub' => $username,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY
    ]);
    
    $base64Header = base64url_encode($header);
    $base64Payload = base64url_encode($payload);
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
    $base64Signature = base64url_encode($signature);
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($header, $payload, $signature) = $parts;
    
    $validSignature = base64url_encode(hash_hmac('sha256', $header . "." . $payload, JWT_SECRET, true));
    
    if (!hash_equals($signature, $validSignature)) {
        return false;
    }
    
    $payloadData = json_decode(base64url_decode($payload), true);
    
    if (!$payloadData || !isset($payloadData['exp']) || $payloadData['exp'] < time()) {
        return false;
    }
    
    return $payloadData;
}

function extractTokenFromHeader() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }
    
    return null;
}

// Input sanitization functions
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    if (!is_string($input)) {
        return $input;
    }
    // Remove HTML tags but don't encode special characters
    return strip_tags($input);
}

function sanitizeFilename($filename) {
    // Remove any characters that could be used for path traversal
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    // Ensure the file has a .csv extension
    if (!preg_match('/\.csv$/', $filename)) {
        throw new InvalidArgumentException('Invalid file extension');
    }
    return $filename;
}

function validateSearchInput($value) {
    // Prevent SQL injection patterns
    $sqlPatterns = '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|ALTER|EXEC|--)\b/i';
    if (preg_match($sqlPatterns, $value)) {
        throw new InvalidArgumentException('Invalid search value');
    }
    return true;
}

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/vnd.api+json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// JWT Auth Function
function checkAuth() {
    global $validUsers;
    
    // Skip auth for login endpoint
    $requestUri = filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL);
    $path = parse_url($requestUri, PHP_URL_PATH);
    $parts = explode("/", explode(basename(__FILE__) . "/", $path)[1] ?? '');
    
    if (count($parts) >= 3 && $parts[0] === 'api' && $parts[1] === 'auth' && $parts[2] === 'login') {
        return;
    }
    
    $token = extractTokenFromHeader();
    
    if (!$token) {
        header('HTTP/1.0 401 Unauthorized');
        die(json_encode([
            'errors' => [
                [
                    'status' => '401',
                    'title' => 'Unauthorized',
                    'detail' => 'JWT token required'
                ]
            ]
        ]));
        exit;
    }
    
    $payload = verifyJWT($token);
    if (!$payload) {
        header('HTTP/1.0 401 Unauthorized');
        die(json_encode([
            'errors' => [
                [
                    'status' => '401',
                    'title' => 'Unauthorized',
                    'detail' => 'Invalid or expired JWT token'
                ]
            ]
        ]));
        exit;
    }
    
    // Store user info in global variable for use in other functions
    $GLOBALS['currentUser'] = $payload['sub'];
}

// Check authentication before processing any request
checkAuth();

class CsvHandler {
    private string $filePath;
    private array $headers;
    private array $data;
    private string $resourceType;

    public function __construct(string $filePath) {
        $this->filePath = $filePath;
        $this->resourceType = pathinfo($filePath, PATHINFO_FILENAME);
        $this->loadData();
    }

    private function loadData(): void {
        if (!file_exists($this->filePath)) {
            if (CSV_DEBUG_ENABLED) {
                error_log("CSV Debug: File not found: {$this->filePath}");
            }
            throw new RuntimeException("CSV file not found: {$this->filePath}");
        }

        if (CSV_DEBUG_ENABLED) {
            error_log("CSV Debug: Opening file for reading: {$this->filePath}");
        }

        $file = fopen($this->filePath, 'r');
        if ($file === false) {
            if (CSV_DEBUG_ENABLED) {
                error_log("CSV Debug: Failed to open file: {$this->filePath}");
            }
            throw new RuntimeException("Could not open file: {$this->filePath}");
        }

        // Read headers
        $csvFile = fgetcsv($file);
        if(!$csvFile) {
            if (CSV_DEBUG_ENABLED) {
                error_log("CSV Debug: No headers found in file: {$this->filePath}");
            }
            fclose($file);
            throw new RuntimeException("Invalid CSV file: No headers found");
        }
        $this->headers = array_map('sanitizeInput', $csvFile);
        if ($this->headers === false) {
            if (CSV_DEBUG_ENABLED) {
                error_log("CSV Debug: Failed to sanitize headers in file: {$this->filePath}");
            }
            fclose($file);
            throw new RuntimeException("Invalid CSV file: No headers found");
        }

        // Debug: Log headers
        if (CSV_DEBUG_ENABLED) {
            error_log("CSV Debug: Headers loaded for file {$this->filePath}: " . implode(', ', $this->headers));
        }

        // Read data
        $this->data = [];
        $lineNumber = 1; // Start at 1 since we already read the header
        $validLines = 0;
        
        while (($row = fgetcsv($file)) !== false) {
            $lineNumber++;
            
            // Debug: Log each line being read
            if (CSV_DEBUG_ENABLED) {
                error_log("CSV Debug: Reading line {$lineNumber} from {$this->filePath}: " . implode(', ', $row));
            }
            
            // Handle column count mismatches by padding or truncating
            if (count($row) !== count($this->headers)) {
                if (CSV_DEBUG_ENABLED) {
                    error_log("CSV Debug: Column count mismatch on line {$lineNumber}. Expected: " . count($this->headers) . ", Got: " . count($row));
                }
                
                if (count($row) < count($this->headers)) {
                    // Fill missing columns with null
                    $missingColumns = count($this->headers) - count($row);
                    $row = array_merge($row, array_fill(0, $missingColumns, null));
                    
                    if (CSV_DEBUG_ENABLED) {
                        error_log("CSV Debug: Filled {$missingColumns} missing columns with null on line {$lineNumber}");
                    }
                } else {
                    // Truncate extra columns
                    $extraColumns = count($row) - count($this->headers);
                    $row = array_slice($row, 0, count($this->headers));
                    
                    if (CSV_DEBUG_ENABLED) {
                        error_log("CSV Debug: Truncated {$extraColumns} extra columns on line {$lineNumber}");
                    }
                }
            }
            
            // Now the row should have the correct number of columns
            $this->data[] = array_combine($this->headers, array_map('sanitizeInput', $row));
            $validLines++;
            
            // Debug: Log successful line processing
            if (CSV_DEBUG_ENABLED) {
                error_log("CSV Debug: Successfully processed line {$lineNumber} - Record added");
            }
        }

        fclose($file);
        
        // Debug: Log summary
        if (CSV_DEBUG_ENABLED) {
            error_log("CSV Debug: File {$this->filePath} loaded successfully. Total lines read: {$lineNumber}, Valid records: {$validLines}");
        }
    }

    private function saveData(): void {
        $file = fopen($this->filePath, 'w');
        if ($file === false) {
            throw new RuntimeException("Could not open file for writing: {$this->filePath}");
        }

        // Write headers
        fputcsv($file, $this->headers);

        // Write data
        foreach ($this->data as $row) {
            fputcsv($file, array_values($row));
        }

        fclose($file);
    }

    private function formatResourceObject(array $row, int $index): array {
        return [
            'type' => $this->resourceType,
            'id' => (string)$index,
            'attributes' => $row
        ];
    }

    public function getAll(int $offset = 0, int $perPage = 50): array {
        $resources = [];
        foreach ($this->data as $index => $row) {
            $resources[] = $this->formatResourceObject($row, $index);
        }
        

        $total = count($resources);
        $paginatedResources = array_slice($resources, $offset, $perPage);
        return [
            'data' => $paginatedResources,
            'meta' => [
                'totalRecords' => $total,
                'page' => [
                    'offset' => $offset,
                    'limit' => $perPage
                ]
            ]
        ];
    }

    public function getAllRecords(): array {
        $resources = [];
        foreach ($this->data as $index => $row) {
            $resources[] = $this->formatResourceObject($row, $index);
        }
        
        return [
            'data' => $resources,
            'meta' => [
                'totalRecords' => count($resources)
            ]
        ];
    }

    public function getById(int $id): ?array {
        if (!isset($this->data[$id])) {
            return null;
        }
        return [
            'data' => $this->formatResourceObject($this->data[$id], $id)
        ];
    }

    public function search(array $criteria, bool $exactMatch = false, int $offset = 0, int $perPage = 50): array {
        // Sanitize search criteria
        $criteria = sanitizeInput($criteria);
        
        // Validate search inputs
        foreach ($criteria as $value) {
            validateSearchInput($value);
        }

        $results = [];
        foreach ($this->data as $index => $row) {
            $match = true;
            foreach ($criteria as $column => $value) {
                if (!isset($row[$column])) {
                    $match = false;
                    break;
                }
                
                if ($exactMatch) {
                    if (strtolower($row[$column]) !== strtolower($value)) {
                        $match = false;
                        break;
                    }
                } else {
                    if (stripos($row[$column], $value) === false) {
                        $match = false;
                        break;
                    }
                }
            }
            
            if ($match) {
                $results[] = $this->formatResourceObject($row, $index);
            }
        }

        $total = count($results);
        $paginatedResults = array_slice($results, $offset, $perPage);
        
        return [
            'data' => $paginatedResults,
            'meta' => [
                'totalRecords' => $total,
                'page' => [
                    'offset' => $offset,
                    'limit' => $perPage
                ]
            ]
        ];
    }

    public function create(array $attributes): int {
        // Sanitize input attributes
        $attributes = sanitizeInput($attributes);

        // Validate that all required headers are present
        foreach ($this->headers as $header) {
            if (!isset($attributes[$header])) {
                throw new InvalidArgumentException("Missing required field: {$header}");
            }
        }

        $this->data[] = $attributes;
        $this->saveData();
        $newId = count($this->data) - 1;
        return $newId;
    }

    public function update(int $id, array $attributes): bool {
        if (!isset($this->data[$id])) {
            return false;
        }

        // Sanitize input attributes
        $attributes = sanitizeInput($attributes);

        // Validate that all provided attributes correspond to valid headers
        foreach ($attributes as $key => $value) {
            if (!in_array($key, $this->headers)) {
                throw new InvalidArgumentException("Invalid field: {$key}");
            }
        }

        // Merge provided attributes with existing data (partial update)
        $this->data[$id] = array_merge($this->data[$id], $attributes);
        $this->saveData();
        return true;
    }

    public function delete(int $id): bool {
        if (!isset($this->data[$id])) {
            return false;
        }

        array_splice($this->data, $id, 1);
        $this->saveData();
        return true;
    }

    public function getHeaders(): array {
        return [
            'data' => [
                'type' => $this->resourceType . '_structure',
                'id' => 'headers',
                'attributes' => [
                    'headers' => $this->headers
                ]
            ]
        ];
    }

    public function debugLine(int $lineNumber): array {
        if (!file_exists($this->filePath)) {
            throw new RuntimeException("CSV file not found: {$this->filePath}");
        }

        $file = fopen($this->filePath, 'r');
        if ($file === false) {
            throw new RuntimeException("Could not open file: {$this->filePath}");
        }

        $currentLine = 0;
        $targetLine = $lineNumber;
        
        while (($row = fgetcsv($file)) !== false) {
            $currentLine++;
            if ($currentLine === $targetLine) {
                fclose($file);
                return [
                    'line_number' => $lineNumber,
                    'raw_content' => $row,
                    'column_count' => count($row),
                    'expected_columns' => count($this->headers),
                    'headers' => $this->headers,
                    'is_valid' => count($row) === count($this->headers),
                    'missing_columns' => count($row) < count($this->headers) ? array_slice($this->headers, count($row)) : [],
                    'extra_columns' => count($row) > count($this->headers) ? array_slice($row, count($this->headers)) : []
                ];
            }
        }

        fclose($file);
        throw new RuntimeException("Line {$lineNumber} not found in file");
    }
}


// Get the request path and sanitize it
$requestUri = filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL);
$path = parse_url($requestUri, PHP_URL_PATH);
$query = parse_url($requestUri, PHP_URL_QUERY);

// Extract the parts of the path
$parts = explode("/", explode(basename(__FILE__) . "/", $path)[1] ?? '');
error_log("parts: " . print_r($parts, true));

// Handle authentication endpoints
if (count($parts) >= 3 && $parts[0] === 'api' && $parts[1] === 'auth') {
    if ($parts[2] === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['username']) || !isset($input['password'])) {
            http_response_code(400);
            die(json_encode([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Bad Request',
                        'detail' => 'Username and password are required'
                    ]
                ]
            ]));
        }
        
        $username = sanitizeInput($input['username']);
        $password = $input['password'];
        
        if (!isset($validUsers[$username]) || !password_verify($password, $validUsers[$username])) {
            http_response_code(401);
            die(json_encode([
                'errors' => [
                    [
                        'status' => '401',
                        'title' => 'Unauthorized',
                        'detail' => 'Invalid credentials'
                    ]
                ]
            ]));
        }
        
        $token = generateJWT($username);
        
        die(json_encode([
            'data' => [
                'type' => 'auth_token',
                'id' => 'login',
                'attributes' => [
                    'token' => $token,
                    'expires_in' => JWT_EXPIRY,
                    'token_type' => 'Bearer'
                ]
            ]
        ]));
    }
    
    http_response_code(404);
    die(json_encode([
        'errors' => [
            [
                'status' => '404',
                'title' => 'Not Found',
                'detail' => 'The requested resource was not found'
            ]
        ]
    ]));
}

// Handle download endpoints
if (count($parts) >= 4 && $parts[0] === 'api' && $parts[1] === 'csv' && $parts[3] === 'download') {
    $filename = $parts[2];
    $filePath = DATA_DIR . '/' . $filename;
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        die(json_encode([
            'errors' => [
                [
                    'status' => '404',
                    'title' => 'Not Found',
                    'detail' => 'The requested file was not found'
                ]
            ]
        ]));
    }
    
    // Set headers for file download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Output the file content
    readfile($filePath);
    exit;
}

// Validate path structure for CSV endpoints
if (count($parts) < 2 || $parts[0] !== 'api' || $parts[1] !== 'csv') {
    http_response_code(404);
    die(json_encode([
        'errors' => [
            [
                'status' => '404',
                'title' => 'Not Found',
                'detail' => 'The requested resource was not found'
            ]
        ]
    ]));
    exit;
}

try {
    // Handle list files endpoint
    if (count($parts) === 2 && $parts[0] === 'api' && $parts[1] === 'csv') {
        error_log("list files");
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $files = glob(DATA_DIR . '/*.csv');
            $fileList = [];
            foreach ($files as $file) {
                $fileList[] = [
                    'type' => 'csv_file',
                    'id' => basename($file),
                    'attributes' => [
                        'filename' => basename($file),
                        'size' => filesize($file),
                        'last_modified' => date('c', filemtime($file))
                    ]
                ];
            }
            die(json_encode([
                'data' => $fileList,
                'meta' => [
                    'totalRecords' => count($fileList)
                ]
            ]));
            exit;
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Handle file upload
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'No file uploaded or upload error'
                        ]
                    ]
                ]));
                exit;
            }

            $file = $_FILES['file'];
            $filename = sanitizeFilename(basename($file['name']));
            
            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if ($mimeType !== 'text/csv' && $mimeType !== 'text/plain') {
                http_response_code(400);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'Invalid file type. Only CSV files are allowed.'
                        ]
                    ]
                ]));
                exit;
            }

            // Move uploaded file to data directory
            $targetPath = DATA_DIR . '/' . $filename;
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                die(json_encode([
                    'data' => [
                        'type' => 'csv_file',
                        'id' => $filename,
                        'attributes' => [
                            'filename' => $filename,
                            'size' => filesize($targetPath),
                            'last_modified' => date('c', filemtime($targetPath))
                        ]
                    ]
                ]));
            } else {
                http_response_code(500);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '500',
                            'title' => 'Internal Server Error',
                            'detail' => 'Failed to save uploaded file'
                        ]
                    ]
                ]));
            }
            exit;
        }
    }

}
catch (Exception $e) {
    http_response_code(400);
    die(json_encode([
        'errors' => [
            [
                'status' => '400',
                'title' => 'Bad Request',
                'detail' => $e->getMessage()
            ]
        ]
    ]));
}

// Handle file deletion
if (count($parts) === 3 && $parts[0] === 'api' && $parts[1] === 'csv' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $filename = $parts[2];
    $filePath = DATA_DIR . '/' . $filename;

    // Validate file extension
    if (pathinfo($filename, PATHINFO_EXTENSION) !== 'csv') {
        http_response_code(400);
        die(json_encode([
            'errors' => [
                [
                    'status' => '400',
                    'title' => 'Bad Request',
                    'detail' => 'Only CSV files can be deleted'
                ]
            ]
        ]));
        exit;
    }

    if (!file_exists($filePath)) {
        http_response_code(404);
        die(json_encode ([
            'errors' => [
                [
                    'status' => '404',
                    'title' => 'Not Found',
                    'detail' => 'The requested file was not found'
                ]
            ]
        ]));
        exit;
    }

    if (unlink($filePath)) {
        http_response_code(204);
    } else {
        http_response_code(500);
        die(json_encode([
            'errors' => [
                [
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Failed to delete file'
                ]
            ]
        ]));
    }
    exit;
}



// Get filename from path

$filename = $parts[2] ?? null;
if (!$filename) {
    http_response_code(400);
    die(json_encode([
        'errors' => [
            [
                'status' => '400',
                'title' => 'Bad Request',
                'detail' => 'Filename is required'
            ]
        ]
    ]));
    exit;
}

$filePath = DATA_DIR . '/' . $filename;
try {
    $csvHandler = new CsvHandler($filePath);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($parts[3]) && $parts[3] === 'structure') {
                die(json_encode($csvHandler->getHeaders()));
            } else if (isset($parts[3]) && $parts[3] === 'all') {
                // Get all records without pagination
                die(json_encode($csvHandler->getAllRecords()));
            } else if (isset($parts[3]) && $parts[3] === 'debug' && isset($parts[4])) {
                // Debug specific line
                $lineNumber = (int)$parts[4];
                try {
                    $debugInfo = $csvHandler->debugLine($lineNumber);
                    die(json_encode([
                        'data' => [
                            'type' => 'debug_info',
                            'id' => 'line_' . $lineNumber,
                            'attributes' => $debugInfo
                        ]
                    ]));
                } catch (Exception $e) {
                    http_response_code(400);
                    die(json_encode([
                        'errors' => [
                            [
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => $e->getMessage()
                            ]
                        ]
                    ]));
                }
            } else if (isset($parts[3]) && $parts[3] === 'search') {
                
            } else if (isset($parts[3])) {
                // Get specific record
                $id = (int)$parts[3];
                $data = $csvHandler->getById($id);
                
                if ($data === null) {
                    http_response_code(404);
                    die(json_encode([
                        'errors' => [
                            [
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => 'The requested resource was not found'
                            ]
                        ]
                    ]));
                    exit;
                }
                
                die(json_encode($data));
            } else {
                parse_str($query, $params);
                $offset = isset($params['page'][$filename]['offset']) ? (int)(sanitizeInput($params['page'][$filename]['offset'])) : 0;
                $perPage = isset($params['page'][$filename]['limit']) ? (int)(sanitizeInput($params['page'][$filename]['limit'])) : 50;
                $exactMatch = false;
                if(isset($params['filter'])) {
                    // Handle search request
                    $tmp = explode(",",$params['filter']);
                    $searchParams = [];
                    foreach($tmp as $t) {
                        $t = explode("=",$t);
                        $searchParams[$t[0]] = $t[1];
                    }
                    die(json_encode($csvHandler->search($searchParams, $exactMatch, $offset, $perPage)));
                }
                // Get all records with pagination
                
                die(json_encode($csvHandler->getAll($offset, $perPage)));
            }
            break;

        case 'POST':
            // Create new record
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input === null || !isset($input['data']['attributes'])) {
                http_response_code(400);
                die(json_encode ([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'Invalid JSON:API request format'
                        ]
                    ]
                ]));
                exit;
            }

            try {
                $id = $csvHandler->create($input['data']['attributes']);
                http_response_code(201);
                die(json_encode([
                    'data' => [
                        'type' => pathinfo($filename, PATHINFO_FILENAME),
                        'id' => (string)$id,
                        'attributes' => $input['data']['attributes']
                    ]
                ]));
                
            } catch (InvalidArgumentException $e) {
                http_response_code(400);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => $e->getMessage()
                        ]
                    ]
                ])); 
            }
            break;

        case 'PATCH':
            if (!isset($parts[3])) {
                http_response_code(400);
                die(json_encode ([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'ID is required'
                        ]
                    ]
                ]));
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if ($input === null || !isset($input['data']['attributes'])) {
                http_response_code(400);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'Invalid JSON:API request format'
                        ]
                    ]
                ]));
                exit;
            }

            $id = (int)$parts[3];
            try {
                $success = $csvHandler->update($id, $input['data']['attributes']);
                
                if (!$success) {
                    http_response_code(404);
                    die(json_encode([
                        'errors' => [
                            [
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => 'The requested resource was not found'
                            ]
                        ]
                    ]));
                    exit;
                }

                // Get the updated record to return complete data
                $updatedRecord = $csvHandler->getById($id);
                
                http_response_code(200);
                die(json_encode($updatedRecord));
            } catch (InvalidArgumentException $e) {
                http_response_code(400);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => $e->getMessage()
                        ]
                    ]
                ]));
            }
            break;

        case 'DELETE':
            if (!isset($parts[3])) {
                http_response_code(400);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'ID is required'
                        ]
                    ]
                ]));
                exit;
            }

            $id = (int)$parts[3];
            $success = $csvHandler->delete($id);
            
            if (!$success) {
                http_response_code(404);
                die(json_encode([
                    'errors' => [
                        [
                            'status' => '404',
                            'title' => 'Not Found',
                            'detail' => 'The requested resource was not found'
                        ]
                    ]
                ]));
                exit;
            }

            http_response_code(204);
            exit;

        default:
            http_response_code(405);
            die(json_encode([
                'errors' => [
                    [
                        'status' => '405',
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed'
                    ]
                ]
            ]));
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    die(json_encode([
        'errors' => [
            [
                'status' => '400',
                'title' => 'Bad Request',
                'detail' => $e->getMessage()
            ]
        ]
    ]));
} 


// Handle file structure endpoint
if (count($parts) === 4 && $parts[0] === 'api' && $parts[1] === 'csv' && $parts[3] === 'structure') {
    error_log("get file structure: ".print_r($parts,true));
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $csvHandler = new CsvHandler($filePath);
            die(json_encode ($csvHandler->getHeaders()));
        } catch (Exception $e) {
            http_response_code(400);
            die(json_encode([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Bad Request',
                        'detail' => $e->getMessage()
                    ]
                ]
            ]));
        }
        exit;
    }
}
