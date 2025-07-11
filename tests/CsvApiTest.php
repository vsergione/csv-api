<?php

use PHPUnit\Framework\TestCase;

class CsvApiTest extends TestCase
{
    private string $dataDir =  __DIR__ . '/../data';
    private string $baseUrl = 'http://localhost:8000/api.php';
    private string $username = 'admin';
    private string $password = 'secret123';
    private string $testFile = 'test_products.csv';
    private array $testData = [
        'id' => '1',
        'product_name' => 'Test Product',
        'price' => '99.99',
        'category' => 'Test',
        'stock' => '50'
    ];
    private ?string $authToken = null;

    protected function setUp(): void
    {
        echo "--".__DIR__."--";
        parent::setUp();
        $this->testFile = $this->dataDir . '/test.csv';
        $this->testData = [
            ['id' => '1', 'name' => 'John Doe', 'email' => 'john@example.com'],
            ['id' => '2', 'name' => 'Jane Smith', 'email' => 'jane@example.com']
        ];
        
        // Create test file
        $file = fopen($this->testFile, 'w');
        fputcsv($file, array_keys($this->testData[0]));
        foreach ($this->testData as $row) {
            fputcsv($file, $row);
        }
        fclose($file);
        
        // Login to get JWT token
        $this->login();
    }

    private function login(): void
    {
        $ch = curl_init();
        $url = $this->baseUrl . '/api/auth/login';

        $headers = [
            'Content-Type: application/json'
        ];

        $data = json_encode([
            'username' => $this->username,
            'password' => $this->password
        ]);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            $this->authToken = $responseData['data']['attributes']['token'];
        }
    }

    protected function tearDown(): void
    {
        
        // Clean up test files
        $files = glob($this->dataDir . '/test*.csv');
        foreach ($files as $file) {
            unlink($file);
        }
        parent::tearDown();
        return;
    }

    private function createTestFile(): void
    {
        $content = "id,name,value\n1,Test Product,99.99\n2,Another Product,149.99";
        file_put_contents($this->dataDir . '/test.csv', $content);
    }

    private function makeRequest($method, $endpoint, $data = null, $isMultipart = false)
    {
        $ch = curl_init();
        $url = $this->baseUrl . $endpoint;
        echo $url."\n";

        $headers = [];

        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        if (!$isMultipart && $data !== null) {
            $headers[] = 'Content-Type: application/vnd.api+json';
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null) {
            if ($isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $httpCode,
            'body' => json_decode($response, true)
        ];
    }

    public function testListFiles()
    {
        echo "\nRunning test: List Files\n";
        $response = $this->makeRequest('GET', '/api/csv');
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('meta', $response['body']);
    }

    public function testUploadFile()
    {
        echo "\nRunning test: Upload File";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, "id,name,email\n3,Bob Wilson,bob@example.com");
        
        $data = [
            'file' => new CURLFile($tempFile, 'text/csv', 'test_upload.csv')
        ];
        
        $response = $this->makeRequest('POST', '/api/csv', $data, true);
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        
        unlink($tempFile);
    }

    public function testDeleteFile()
    {
        echo "\nRunning test: Delete File";
        $response = $this->makeRequest('DELETE', '/api/csv/test.csv');
        $this->assertEquals(204, $response['code']);
    }

    public function testDownloadFile()
    {
        echo "\nRunning test: Download File";
        $ch = curl_init();
        $url = $this->baseUrl . '/api/csv/test.csv/download';

        $headers = [];
        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode);
        
        // Verify the response contains CSV content
        $this->assertStringContainsString('id,name,email', $response);
        $this->assertStringContainsString('1,John Doe,john@example.com', $response);
    }

    public function testDownloadNonExistentFile()
    {
        echo "\nRunning test: Download Non-existent File";
        $ch = curl_init();
        $url = $this->baseUrl . '/api/csv/nonexistent.csv/download';

        $headers = [];
        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(404, $httpCode);
        $responseData = json_decode($response, true);
        $this->assertEquals('The requested file was not found', $responseData['errors'][0]['detail']);
    }

    public function testGetAllRecords()
    {
        echo "\nRunning test: Get All Records";
        $response = $this->makeRequest('GET', '/api/csv/test.csv');
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('meta', $response['body']);
        $this->assertCount(2, $response['body']['data']);
        $this->assertEquals(2, $response['body']['meta']['total']);
    }

    public function testGetAllRecordsWithPagination()
    {
        echo "\nRunning test: Get All Records With Pagination";
        // Create more test data
        $file = fopen($this->testFile, 'w');
        fputcsv($file, ['id', 'name', 'email']);
        for ($i = 1; $i <= 15; $i++) {
            fputcsv($file, ["$i", "User $i", "user$i@example.com"]);
        }
        fclose($file);

        // Test first page
        $response = $this->makeRequest('GET', '/api/csv/test.csv?page[offset]=0&page[limit]=5');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(5, $response['body']['data']);
        $this->assertEquals(15, $response['body']['meta']['total']);
        // Test second page
        $response = $this->makeRequest('GET', '/api/csv/test.csv?page[offset]=5&page[limit]=5');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(5, $response['body']['data']);

        // Test last page
        $response = $this->makeRequest('GET', '/api/csv/test.csv?page[offset]=10&page[limit]=5');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(5, $response['body']['data']);

        // Test invalid page
        $response = $this->makeRequest('GET', '/api/csv/test.csv?page[offset]=999&page[limit]=5');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(0, $response['body']['data']);
    }

    public function testGetSpecificRecord()
    {
        echo "\nRunning test: Get Specific Record";
        $response = $this->makeRequest('GET', '/api/csv/test.csv/0');
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertEquals('1', $response['body']['data']['attributes']['id']);
    }

    public function testSearchRecords()
    {
        echo "\nRunning test: Search Records";
        $response = $this->makeRequest('GET', '/api/csv/test.csv/search?name=John');
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('meta', $response['body']);
        $this->assertCount(1, $response['body']['data']);
    }

    public function testSearchRecordsWithPagination()
    {
        echo "\nRunning test: Search Records With Pagination";
        // Create test data with multiple matches
        $file = fopen($this->testFile, 'w');
        fputcsv($file, ['id', 'name', 'email']);
        for ($i = 1; $i <= 10; $i++) {
            fputcsv($file, ["$i", "John $i", "john$i@example.com"]);
        }
        fclose($file);

        // Test first page of search results
        $response = $this->makeRequest('GET', '/api/csv/test.csv/search?name=John&page[offset]=0&page[limit]=3');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(3, $response['body']['data']);
        $this->assertEquals(10, $response['body']['meta']['total']);

        // Test second page of search results
        $response = $this->makeRequest('GET', '/api/csv/test.csv/search?name=John&page[offset]=5&page[limit]=3');
        $this->assertEquals(200, $response['code']);
        $this->assertCount(3, $response['body']['data']);
    }

    public function testCreateRecord()
    {
        echo "\nRunning test: Create Record";
        $data = [
            'data' => [
                'attributes' => [
                    'id' => '3',
                    'name' => 'Bob Wilson',
                    'email' => 'bob@example.com'
                ]
            ]
        ];
        
        $response = $this->makeRequest('POST', '/api/csv/test.csv', $data);
        $this->assertEquals(201, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
    }

    public function testUpdateRecord()
    {
        echo "\nRunning test: Update Record";
        $data = [
            'data' => [
                'attributes' => [
                    'id' => '1',
                    'name' => 'John Updated',
                    'email' => 'john.updated@example.com'
                ]
            ]
        ];
        
        $response = $this->makeRequest('PATCH', '/api/csv/test.csv/0', $data);
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertEquals('John Updated', $response['body']['data']['attributes']['name']);
        $this->assertEquals('john.updated@example.com', $response['body']['data']['attributes']['email']);
    }

    public function testPartialUpdateRecord()
    {
        echo "\nRunning test: Partial Update Record";
        // First, ensure we have a record to update
        $createData = [
            'data' => [
                'attributes' => [
                    'id' => '3',
                    'name' => 'Bob Wilson',
                    'email' => 'bob@example.com'
                ]
            ]
        ];
        
        $this->makeRequest('POST', '/api/csv/test.csv', $createData);
        
        // Now test partial update - only update the name
        $partialData = [
            'data' => [
                'attributes' => [
                    'name' => 'Bob Updated'
                ]
            ]
        ];
        
        $response = $this->makeRequest('PATCH', '/api/csv/test.csv/2', $partialData);
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertEquals('Bob Updated', $response['body']['data']['attributes']['name']);
        $this->assertEquals('bob@example.com', $response['body']['data']['attributes']['email']); // Should remain unchanged
        $this->assertEquals('3', $response['body']['data']['attributes']['id']); // Should remain unchanged
    }

    public function testPartialUpdateWithInvalidField()
    {
        echo "\nRunning test: Partial Update With Invalid Field";
        $invalidData = [
            'data' => [
                'attributes' => [
                    'invalid_field' => 'This field does not exist'
                ]
            ]
        ];
        
        $response = $this->makeRequest('PATCH', '/api/csv/test.csv/0', $invalidData);
        $this->assertEquals(400, $response['code']);
        $this->assertEquals('Invalid field: invalid_field', $response['body']['errors'][0]['detail']);
    }

    public function testDeleteRecord()
    {
        echo "\nRunning test: Delete Record";
        $response = $this->makeRequest('DELETE', '/api/csv/test.csv/0');
        $this->assertEquals(204, $response['code']);
    }

    public function testInvalidAuthentication()
    {
        echo "\nRunning test: Invalid Authentication";
        $this->username = 'invalid';
        $response = $this->makeRequest('GET', '/api/csv');
        $this->assertEquals(401, $response['code']);
    }

    public function testInvalidFileType()
    {
        echo "\nRunning test: Invalid File Type";
        // Try to upload a non-CSV file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, "This is not a CSV file");

        $data = [
            'file' => new CURLFile($tempFile, 'text/plain', 'test.txt')
        ];

        $response = $this->makeRequest('POST', '/api/csv', $data, true);
        print_r($response);
        $this->assertEquals(400, $response['code']);

        unlink($tempFile);
    }

    public function testNonExistentFile()
    {
        echo "\nRunning test: Non Existent File";
        $response = $this->makeRequest('GET', '/api/csv/nonexistent.csv');
        $this->assertEquals(400, $response['code']);
    }

    public function testInvalidJsonFormat()
    {
        echo "\nRunning test: Invalid Json Format";
        $data = ['invalid' => 'format'];
        $response = $this->makeRequest('POST', '/api/csv/' . $this->testFile, $data);
        $this->assertEquals(400, $response['code']);
    }

    public function testInvalidFile()
    {
        echo "\nRunning test: Invalid File";
        $response = $this->makeRequest('GET', '/api/csv/nonexistent.csv');
        $this->assertEquals(400, $response['code']);
    }

    public function testInvalidRecord()
    {
        echo "\nRunning test: Invalid Record";
        $response = $this->makeRequest('GET', '/api/csv/test.csv/999');
        $this->assertEquals(404, $response['code']);
    }

    public function testInvalidSearch()
    {
        echo "\nRunning test: Invalid Search";
        $response = $this->makeRequest('GET', '/api/csv/test.csv/search');
        $this->assertEquals(400, $response['code']);
    }

    public function testInvalidMethod()
    {
        echo "\nRunning test: Invalid Method";
        $response = $this->makeRequest('PATCH', '/api/csv/test.csv');
        $this->assertEquals(405, $response['code']);
    }

    public function testGetFileStructure()
    {
        // Create a test file
        $testFile = $this->dataDir . '/test.csv';
        file_put_contents($testFile, "id,name,description\n1,Test,Description");

        $response = $this->makeRequest('GET', '/api/csv/test.csv/structure');
        $this->assertEquals(200, $response['code']);
        print_r($response);
        $this->assertEquals('test_structure', $response['body']['data']['type']);
        $this->assertEquals(['id', 'name', 'description'], $response['body']['data']['attributes']['headers']);

        // Test with non-existent file
        $response = $this->makeRequest('GET', '/api/csv/nonexistent.csv/structure');
        $this->assertEquals(400, $response['code']);
    }

    public function testInputSanitization()
    {
        // Create a test file with clean data
        $testFile = $this->dataDir . '/sanitize_test.csv';
        file_put_contents($testFile, "id,name,description\n1,Clean,Data");

        // Test XSS prevention in create
        $xssData = [
            'data' => [
                'type' => 'sanitize_test',
                'attributes' => [
                    'id' => '2',
                    'name' => '<script>alert("xss")</script>',
                    'description' => '"><script>alert("xss")</script>'
                ]
            ]
        ];

        $response = $this->makeRequest('POST', '/api/csv/sanitize_test.csv', $xssData);
        $this->assertEquals(201, $response['code']);
        
        // Verify the data was sanitized
        $response = $this->makeRequest('GET', '/api/csv/sanitize_test.csv/1');
        $this->assertEquals(200, $response['code']);
        $this->assertStringNotContainsString('<script>', $response['body']['data']['attributes']['name']);
        $this->assertStringNotContainsString('<script>', $response['body']['data']['attributes']['description']);

        // Test SQL injection prevention in search
        $sqlInjectionData = [
            'name' => "'; DROP TABLE users; --"
        ];

        $response = $this->makeRequest('GET', '/api/csv/sanitize_test.csv/search?' . http_build_query($sqlInjectionData));
        $this->assertEquals(400, $response['code']);
        $this->assertEquals('Invalid search value', $response['body']['errors'][0]['detail']);
    }

    public function testFileUploadSecurity()
    {
        // Test file extension validation
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, '<?php echo "malicious"; ?>');

        $data = [
            'file' => new CURLFile($tempFile, 'application/x-httpd-php', 'test.php')
        ];
        $response = $this->makeRequest('POST', '/api/csv', $data);
        $this->assertEquals(400, $response['code']);
        $this->assertEquals('No file uploaded or upload error', $response['body']['errors'][0]['detail']);


        // Test path traversal prevention
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test,data');
        $data = [
            'file' => new CURLFile($tempFile, 'application/csv', 'test.csv')
        ];
        $data["file"]->name = '../../../etc/passwd.csv';

        // Test path traversal prevention
        $response = $this->makeRequest('POST', '/api/csv', $data);
        print_r($response);

        $this->assertEquals(400, $response['code']);
        $this->assertEquals('No file uploaded or upload error', $response['body']['errors'][0]['detail']);

    }

    public function testUrlSanitization()
    {
        // Test URL path sanitization
        // $response = $this->makeRequest('GET', '/api/csv/../../../etc/passwd');
        // print_r($response);
        // $this->assertEquals(404, $response['code']);

        // Test URL parameter sanitization
        $response = $this->makeRequest('GET', '/api/csv/test.csv?page[offset]=<script>alert(1)</script>');
        print_r($response);
        $this->assertEquals(200, $response['code']);
    }

    private function createTempFile($content, $extension)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.' . $extension;
        file_put_contents($tempFile, $content);
        return $tempFile;
    }
} 