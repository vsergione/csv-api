<?php

// Basic Auth Configuration
define('AUTH_USERNAME', 'admin');
define('AUTH_PASSWORD', 'secret123');
define('DATA_DIR', __DIR__ . '/data');

// Basic Auth Function
function checkAuth() {
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
        header('WWW-Authenticate: Basic realm="CSV API"');
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode([
            'errors' => [
                [
                    'status' => '401',
                    'title' => 'Unauthorized',
                    'detail' => 'Authentication required'
                ]
            ]
        ]);
        exit;
    }

    if ($_SERVER['PHP_AUTH_USER'] !== AUTH_USERNAME || $_SERVER['PHP_AUTH_PW'] !== AUTH_PASSWORD) {
        header('HTTP/1.0 401 Unauthorized');
        echo json_encode([
            'errors' => [
                [
                    'status' => '401',
                    'title' => 'Unauthorized',
                    'detail' => 'Invalid credentials'
                ]
            ]
        ]);
        exit;
    }
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
            throw new RuntimeException("CSV file not found: {$this->filePath}");
        }

        $file = fopen($this->filePath, 'r');
        if ($file === false) {
            throw new RuntimeException("Could not open file: {$this->filePath}");
        }

        // Read headers
        $csvFile = fgetcsv($file);
        if(!$csvFile) {
            fclose($file);
            throw new RuntimeException("Invalid CSV file: No headers found");
        }
        $this->headers = $csvFile;
        if ($this->headers === false) {
            fclose($file);
            throw new RuntimeException("Invalid CSV file: No headers found");
        }

        // Read data
        $this->data = [];
        while (($row = fgetcsv($file)) !== false) {
            if (count($row) === count($this->headers)) {
                $this->data[] = array_combine($this->headers, $row);
            }
        }

        fclose($file);
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

    public function getAll(int $offset = 0, int $perPage = 10): array {
        $resources = [];
        foreach ($this->data as $index => $row) {
            $resources[] = $this->formatResourceObject($row, $index);
        }
        

        $total = count($resources);
        $paginatedResources = array_slice($resources, $offset, $perPage);
        return [
            'data' => $paginatedResources,
            'meta' => [
                'total' => $total,
                'page' => [
                    'offset' => $offset,
                    'limit' => $perPage
                ]
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

    public function search(array $criteria, bool $exactMatch = false, int $offset = 0, int $perPage = 10): array {
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
                'total' => $total,
                'page' => [
                    'offset' => $offset,
                    'limit' => $perPage
                ]
            ]
        ];
    }

    public function create(array $attributes): int {
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

        // Validate that all required headers are present
        foreach ($this->headers as $header) {
            if (!isset($attributes[$header])) {
                throw new InvalidArgumentException("Missing required field: {$header}");
            }
        }

        $this->data[$id] = $attributes;
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
}

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/vnd.api+json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get the request path
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$query = parse_url($requestUri, PHP_URL_QUERY);

// Remove leading slash and split path
$path = ltrim($path, '/');
$parts = explode('/', $path);
// Check if the first part is csv_api.php
if ($parts[0] !== 'csv_api.php') {
    http_response_code(404);
    echo json_encode([
        'errors' => [
            [
                'status' => '404',
                'title' => 'Not Found',
                'detail' => 'The requested resource was not found'
            ]
        ]
    ]);
    exit;
}

// Remove csv_api.php from the parts array
array_shift($parts);
error_log("parts: ". $_SERVER['REQUEST_METHOD']." ".$requestUri." ".print_r($parts,true));

// Create data directory if it doesn't exist

// Validate path structure for other endpoints
if (count($parts) < 2 || $parts[0] !== 'api' || $parts[1] !== 'csv') {
    http_response_code(404);
    echo json_encode([
        'errors' => [
            [
                'status' => '404',
                'title' => 'Not Found',
                'detail' => 'The requested resource was not found'
            ]
        ]
    ]);
    exit;
}

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
        echo json_encode([
            'data' => $fileList,
            'meta' => [
                'total' => count($fileList)
            ]
        ]);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Bad Request',
                        'detail' => 'No file uploaded or upload error'
                    ]
                ]
            ]);
            exit;
        }

        $file = $_FILES['file'];
        $filename = basename($file['name']);
        
        // Validate file extension
        if (pathinfo($filename, PATHINFO_EXTENSION) !== 'csv') {
            http_response_code(400);
            echo json_encode([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Bad Request',
                        'detail' => 'Only CSV files are allowed'
                    ]
                ]
            ]);
            exit;
        }

        // Move uploaded file to data directory
        $targetPath = DATA_DIR . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode([
                'data' => [
                    'type' => 'csv_file',
                    'id' => $filename,
                    'attributes' => [
                        'filename' => $filename,
                        'size' => filesize($targetPath),
                        'last_modified' => date('c', filemtime($targetPath))
                    ]
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'errors' => [
                    [
                        'status' => '500',
                        'title' => 'Internal Server Error',
                        'detail' => 'Failed to save uploaded file'
                    ]
                ]
            ]);
        }
        exit;
    }
}

// Handle file deletion
if (count($parts) === 3 && $parts[0] === 'api' && $parts[1] === 'csv' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $filename = $parts[2];
    $filePath = DATA_DIR . '/' . $filename;

    // Validate file extension
    if (pathinfo($filename, PATHINFO_EXTENSION) !== 'csv') {
        http_response_code(400);
        echo json_encode([
            'errors' => [
                [
                    'status' => '400',
                    'title' => 'Bad Request',
                    'detail' => 'Only CSV files can be deleted'
                ]
            ]
        ]);
        exit;
    }

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode([
            'errors' => [
                [
                    'status' => '404',
                    'title' => 'Not Found',
                    'detail' => 'The requested file was not found'
                ]
            ]
        ]);
        exit;
    }

    if (unlink($filePath)) {
        http_response_code(204);
    } else {
        http_response_code(500);
        echo json_encode([
            'errors' => [
                [
                    'status' => '500',
                    'title' => 'Internal Server Error',
                    'detail' => 'Failed to delete file'
                ]
            ]
        ]);
    }
    exit;
}

// Handle file structure endpoint
if (count($parts) === 4 && $parts[0] === 'api' && $parts[1] === 'csv' && $parts[3] === 'structure' && false) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $csvHandler = new CsvHandler($filePath);
            echo json_encode($csvHandler->getHeaders());
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'errors' => [
                    [
                        'status' => '400',
                        'title' => 'Bad Request',
                        'detail' => $e->getMessage()
                    ]
                ]
            ]);
        }
        exit;
    }
}



// Get filename from path

$filename = $parts[2] ?? null;
if (!$filename) {
    http_response_code(400);
    echo json_encode([
        'errors' => [
            [
                'status' => '400',
                'title' => 'Bad Request',
                'detail' => 'Filename is required'
            ]
        ]
    ]);
    exit;
}

$filePath = DATA_DIR . '/' . $filename;
try {
    $csvHandler = new CsvHandler($filePath);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($parts[3]) && $parts[3] === 'structure') {
                echo json_encode($csvHandler->getHeaders());
            } else if (isset($parts[3]) && $parts[3] === 'search') {
                // Handle search request
                parse_str($query, $searchParams);
                $exactMatch = isset($searchParams['exact']) && $searchParams['exact'] === 'true';
                unset($searchParams['exact']); // Remove the exact parameter from search criteria
                
                if (empty($searchParams)) {
                    http_response_code(400);
                    echo json_encode([
                        'errors' => [
                            [
                                'status' => '400',
                                'title' => 'Bad Request',
                                'detail' => 'Search criteria required'
                            ]
                        ]
                    ]);
                    exit;
                }

                $offset = isset($searchParams['page']['offset']) ? (int)($searchParams['page']['offset']): 0 ;
                $perPage = isset($searchParams['page']['limit']) ? (int)$searchParams['page']['limit'] : 10;
                unset($searchParams['page']); // Remove pagination parameters from search criteria
                
                echo json_encode($csvHandler->search($searchParams, $exactMatch, $offset, $perPage));
            } else if (isset($parts[3])) {
                // Get specific record
                $id = (int)$parts[3];
                $data = $csvHandler->getById($id);
                
                if ($data === null) {
                    http_response_code(404);
                    echo json_encode([
                        'errors' => [
                            [
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => 'The requested resource was not found'
                            ]
                        ]
                    ]);
                    exit;
                }
                
                echo json_encode($data);
            } else {
                // Get all records with pagination
                parse_str($query, $params);
                $offset = isset($params['page']['offset']) ? (int)$params['page']['offset'] : 0;
                $perPage = isset($params['page']['limit']) ? (int)$params['page']['limit'] : 10;
                echo json_encode($csvHandler->getAll($offset, $perPage));
            }
            break;

        case 'POST':
            // Create new record
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input === null || !isset($input['data']['attributes'])) {
                http_response_code(400);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'Invalid JSON:API request format'
                        ]
                    ]
                ]);
                exit;
            }

            try {
                $id = $csvHandler->create($input['data']['attributes']);
                http_response_code(201);
                echo json_encode([
                    'data' => [
                        'type' => pathinfo($filename, PATHINFO_FILENAME),
                        'id' => (string)$id,
                        'attributes' => $input['data']['attributes']
                    ]
                ]);
            } catch (InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => $e->getMessage()
                        ]
                    ]
                ]);
            }
            break;

        case 'PUT':
            if (!isset($parts[3])) {
                http_response_code(400);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'ID is required'
                        ]
                    ]
                ]);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if ($input === null || !isset($input['data']['attributes'])) {
                http_response_code(400);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'Invalid JSON:API request format'
                        ]
                    ]
                ]);
                exit;
            }

            $id = (int)$parts[3];
            try {
                $success = $csvHandler->update($id, $input['data']['attributes']);
                
                if (!$success) {
                    http_response_code(404);
                    echo json_encode([
                        'errors' => [
                            [
                                'status' => '404',
                                'title' => 'Not Found',
                                'detail' => 'The requested resource was not found'
                            ]
                        ]
                    ]);
                    exit;
                }

                http_response_code(200);
                echo json_encode([
                    'data' => [
                        'type' => pathinfo($filename, PATHINFO_FILENAME),
                        'id' => (string)$id,
                        'attributes' => $input['data']['attributes']
                    ]
                ]);
            } catch (InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => $e->getMessage()
                        ]
                    ]
                ]);
            }
            break;

        case 'DELETE':
            if (!isset($parts[3])) {
                http_response_code(400);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '400',
                            'title' => 'Bad Request',
                            'detail' => 'ID is required'
                        ]
                    ]
                ]);
                exit;
            }

            $id = (int)$parts[3];
            $success = $csvHandler->delete($id);
            
            if (!$success) {
                http_response_code(404);
                echo json_encode([
                    'errors' => [
                        [
                            'status' => '404',
                            'title' => 'Not Found',
                            'detail' => 'The requested resource was not found'
                        ]
                    ]
                ]);
                exit;
            }

            http_response_code(204);
            exit;

        default:
            http_response_code(405);
            echo json_encode([
                'errors' => [
                    [
                        'status' => '405',
                        'title' => 'Method Not Allowed',
                        'detail' => 'The requested method is not allowed'
                    ]
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'errors' => [
            [
                'status' => '400',
                'title' => 'Bad Request',
                'detail' => $e->getMessage()
            ]
        ]
    ]);
} 