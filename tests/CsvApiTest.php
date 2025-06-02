<?php

use PHPUnit\Framework\TestCase;

class CsvApiTest extends TestCase
{
    private string $dataDir =  __DIR__ . '/../data';
    private string $baseUrl = 'http://localhost:8000/csv_api.php';
    private $username = 'admin';
    private $password = 'secret123';
    private $testFile = 'test_products.csv';
    private $testData = [
        'id' => '1',
        'product_name' => 'Test Product',
        'price' => '99.99',
        'category' => 'Test',
        'stock' => '50'
    ];

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
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $files = glob($this->dataDir . '/test*.csv');
        foreach ($files as $file) {
            unlink($file);
        }
        parent::tearDown();
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

        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password)
        ];

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
        echo "\nRunning test: List Files";
        $response = $this->makeRequest('GET', '/api/csv');
        print_r($response);
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

    public function testGetAllRecords()
    {
        echo "\nRunning test: Get All Records";
        $response = $this->makeRequest('GET', '/api/csv/test.csv');
        print_r($response);
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
        
        $response = $this->makeRequest('PUT', '/api/csv/test.csv/0', $data);
        $this->assertEquals(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
    }

    public function testDeleteRecord()
    {
        echo "\nRunning test: Delete Record";
        $response = $this->makeRequest('DELETE', '/api/csv/test.csv/0');
        $this->assertEquals(204, $response['code']);
    }

    public function testInvalidAuthentication()
    {
        $this->username = 'invalid';
        $response = $this->makeRequest('GET', '/api/csv');
        $this->assertEquals(401, $response['code']);
    }

    public function testInvalidFileType()
    {
        // Try to upload a non-CSV file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, "This is not a CSV file");

        $data = [
            'file' => new CURLFile($tempFile, 'text/plain', 'test.txt')
        ];

        $response = $this->makeRequest('POST', '/api/csv', $data, true);
        $this->assertEquals(400, $response['code']);

        unlink($tempFile);
    }

    public function testNonExistentFile()
    {
        $response = $this->makeRequest('GET', '/api/csv/nonexistent.csv');
        $this->assertEquals(400, $response['code']);
    }

    public function testInvalidJsonFormat()
    {
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
        echo "\nRunning test: Get File Structure";

        // Create test file
        $this->createTestFile();

        // Test getting file structure
        $response = $this->makeRequest('GET', '/api/csv/test.csv/structure');
        $this->assertEquals(200, $response['code']);
        
        $data = $response['body'];
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('test_structure', $data['data']['type']);
        $this->assertEquals('headers', $data['data']['id']);
        $this->assertArrayHasKey('attributes', $data['data']);
        $this->assertArrayHasKey('headers', $data['data']['attributes']);
        print_r($data['data']['attributes']['headers']);
        $this->assertEquals(['id', 'name', 'value'], $data['data']['attributes']['headers']);

        // Test with non-existent file
        $response = $this->makeRequest('GET', '/api/csv/nonexistent.csv/structure');
        $this->assertEquals(400, $response['code']);
        
        $data = $response['body'];
        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals('Bad Request', $data['errors'][0]['title']);
    }

    public function testGetFileStructureWithInvalidFile()
    {
        // Create an invalid CSV file (empty)
        file_put_contents($this->dataDir . '/invalid.csv', '');

        $response = $this->makeRequest('GET', '/api/csv/invalid.csv/structure');
        $this->assertEquals(400, $response['code']);
        
        $data = $response['body'];
        $this->assertArrayHasKey('errors', $data);
        $this->assertEquals('Bad Request', $data['errors'][0]['title']);
    }
} 