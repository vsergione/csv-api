# CSV API

A RESTful API for managing CSV files with JSON:API specification compliance. This API allows you to perform CRUD operations on CSV files, including file management and record manipulation.

## Features

- Basic Authentication
- JSON:API specification compliance
- File operations (list, upload, delete)
- Record operations (CRUD)
- Search functionality
- Pagination support
- CORS enabled
- File structure inspection

## Requirements

- PHP 7.4 or higher
- Web server (Apache/Nginx)
- Write permissions for the data directory

## Installation

1. Clone the repository:

    ```bash
    git clone <repository-url>
    cd csv-api
    ```

2. Create the data directory:

    ```bash
    mkdir data
    chmod 777 data
    ```

3. Update the constant `csv_api.php` storing the path to the data directory.

    ```php
    define('DATA_DIR', __DIR__.'/data');
    ```

4. Configure your web server to point to the project directory.
5. Start the PHP development server (optional):

    ```bash
    php -S localhost:8000
    ```

## Authentication

The API uses Basic Authentication. Default credentials:

- Username: `admin`
- Password: `secret123`

To change the credentials, modify the constants in `csv_api.php`:

```php
define('AUTH_USERNAME', 'your_username');
define('AUTH_PASSWORD', 'your_password');
define('DATA_DIR', __DIR__.'/data');
```

## API Endpoints

### File Management

#### List Files

```http
GET /api/csv
```

Response:

```json
{
    "data": [
        {
            "type": "csv_file",
            "id": "example.csv",
            "attributes": {
                "filename": "example.csv",
                "size": 1024,
                "last_modified": "2024-03-20T12:00:00+00:00"
            }
        }
    ],
    "meta": {
        "total": 1
    }
}
```

#### Upload File

```http
POST /api/csv
Content-Type: multipart/form-data

file: <csv_file>
```

Response:

```json
{
    "data": {
        "type": "csv_file",
        "id": "example.csv",
        "attributes": {
            "filename": "example.csv",
            "size": 1024,
            "last_modified": "2024-03-20T12:00:00+00:00"
        }
    }
}
```

#### Delete File

```http
DELETE /api/csv/{filename}
```

Response: 204 No Content

### Record Management

#### List Records

```http
GET /api/csv/{filename}?page[offset]=0&page[limit]=10
```

Response:

```json
{
    "data": [
        {
            "type": "example",
            "id": "0",
            "attributes": {
                "id": "1",
                "name": "John Doe",
                "email": "john@example.com"
            }
        }
    ],
    "meta": {
        "total": 1
    }
}
```

#### Get Specific Record

```http
GET /api/csv/{filename}/{id}
```

Response:

```json
{
    "data": {
        "type": "example",
        "id": "0",
        "attributes": {
            "id": "1",
            "name": "John Doe",
            "email": "john@example.com"
        }
    }
}
```

#### Create Record

```http
POST /api/csv/{filename}
Content-Type: application/vnd.api+json

{
    "data": {
        "attributes": {
            "id": "2",
            "name": "Jane Smith",
            "email": "jane@example.com"
        }
    }
}
```

Response: 201 Created

#### Update Record

```http
PUT /api/csv/{filename}/{id}
Content-Type: application/vnd.api+json

{
    "data": {
        "attributes": {
            "id": "1",
            "name": "John Updated",
            "email": "john.updated@example.com"
        }
    }
}
```

Response: 200 OK

#### Delete Record

```http
DELETE /api/csv/{filename}/{id}
```

Response: 204 No Content

### Search and Structure

#### Search Records

```http
GET /api/csv/{filename}/search?name=John&exact=true&page[offset]=0&page[limit]=10
```

Response:

```json
{
    "data": [
        {
            "type": "example",
            "id": "0",
            "attributes": {
                "id": "1",
                "name": "John Doe",
                "email": "john@example.com"
            }
        }
    ],
    "meta": {
        "total": 1
    }
}
```

#### Get File Structure

```http
GET /api/csv/{filename}/structure
```

Response:

```json
{
    "data": {
        "type": "example_structure",
        "id": "headers",
        "attributes": {
            "headers": ["id", "name", "email"]
        }
    }
}
```

## Error Responses

All error responses follow the JSON:API error format:

```json
{
    "errors": [
        {
            "status": "400",
            "title": "Bad Request",
            "detail": "Error message"
        }
    ]
}
```

Common HTTP status codes:

- 200: Success
- 201: Created
- 204: No Content
- 400: Bad Request
- 401: Unauthorized
- 404: Not Found
- 405: Method Not Allowed
- 500: Internal Server Error

## Frontend Interface

A web interface is available for managing CSV files. To use it:

1. Open `index.html` in a web browser
2. Log in with the default credentials (admin/secret123)
3. Use the interface to:
   - Upload CSV files
   - View file contents
   - Edit records
   - Delete files and records
   - Search records
4. Double click on the row to open the edit row dialog

## Testing

Run the test suite using PHPUnit:

```bash
composer install
./vendor/bin/phpunit
```

## Security Considerations

1. Change the default authentication credentials
2. Ensure the data directory is not publicly accessible
3. Validate file uploads
4. Implement rate limiting in production
5. Use HTTPS in production

## License

This project is licensed under the MIT License - see the LICENSE file for details.
