# CSV API

A RESTful API for managing CSV files with JSON:API specification compliance. This API allows you to perform CRUD operations on CSV files, including file management and record manipulation.

## Features

- JWT Authentication
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

3. Update the constants in `api.php`:

    ```php
    define('JWT_SECRET', 'your-secure-secret-key');  // Change this to a secure secret
    define('DATA_DIR', __DIR__.'/data');
    ```

4. Configure your web server to point to the project directory.
5. Start the PHP development server (optional):

    ```bash
    php -S localhost:8000
    ```

## Authentication

The API uses JWT (JSON Web Token) authentication. Default credentials:

- Username: `admin`
- Password: `secret123`

### Login Endpoint

To authenticate, make a POST request to the login endpoint:

```http
POST /api/auth/login
Content-Type: application/json

{
    "username": "admin",
    "password": "secret123"
}
```

Response:

```json
{
    "data": {
        "type": "auth_token",
        "id": "login",
        "attributes": {
            "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
            "expires_in": 3600,
            "token_type": "Bearer"
        }
    }
}
```

### Using the Token

Include the JWT token in the Authorization header for all subsequent requests:

```http
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

### Security Configuration

To change the credentials and security settings, modify the constants in `api.php`:

```php
// JWT Configuration
define('JWT_SECRET', 'your-secure-secret-key');  // Use a strong, random secret
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRY', 3600); // Token expiry in seconds

// User credentials (in production, use a database)
$validUsers = [
    'admin' => password_hash('your_password', PASSWORD_DEFAULT)
];
```

## API Endpoints

### Authentication

#### Login

```http
POST /api/auth/login
```

### File Management

#### List Files

```http
GET /api/csv
Authorization: Bearer <token>
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
Authorization: Bearer <token>
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
Authorization: Bearer <token>
```

#### Download File

```http
GET /api/csv/{filename}/download
Authorization: Bearer <token>
```

This endpoint will download the CSV file directly. The file will be served with appropriate headers for download.

**Note:** This endpoint returns the raw CSV file content, not JSON. The browser will automatically download the file.

### Record Operations

#### Get All Records

```http
GET /api/csv/{filename}
Authorization: Bearer <token>
```

With pagination:

```http
GET /api/csv/{filename}?page[offset]=0&page[limit]=10
Authorization: Bearer <token>
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
        "total": 1,
        "page": {
            "offset": 0,
            "limit": 10
        }
    }
}
```

#### Get Specific Record

```http
GET /api/csv/{filename}/{id}
Authorization: Bearer <token>
```

#### Create Record

```http
POST /api/csv/{filename}
Authorization: Bearer <token>
Content-Type: application/vnd.api+json

{
    "data": {
        "attributes": {
            "id": "3",
            "name": "Bob Wilson",
            "email": "bob@example.com"
        }
    }
}
```

#### Update Record

```http
PATCH /api/csv/{filename}/{id}
Authorization: Bearer <token>
Content-Type: application/vnd.api+json

{
    "data": {
        "attributes": {
            "name": "John Updated"
        }
    }
}
```

Response:

```json
{
    "data": {
        "type": "example",
        "id": "0",
        "attributes": {
            "id": "1",
            "name": "John Updated",
            "email": "john@example.com"
        }
    }
}
```

**Note:** The PATCH method supports partial updates. You only need to include the fields you want to update. The response will contain the complete updated record.

#### Delete Record

```http
DELETE /api/csv/{filename}/{id}
Authorization: Bearer <token>
```

#### Search Records

```http
GET /api/csv/{filename}/search?{field}={value}&exact=true
Authorization: Bearer <token>
```

#### Get File Structure

```http
GET /api/csv/{filename}/structure
Authorization: Bearer <token>
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
3. The interface will automatically handle JWT token management
4. Use the interface to:
   - Upload CSV files
   - View file contents
   - Edit records
   - Delete files and records
   - Search records
5. Double click on the row to open the edit row dialog

## Testing

Run the test suite using PHPUnit:

```bash
composer install
./vendor/bin/phpunit
```

## Security Considerations

1. Change the default JWT secret to a secure, random value
2. Use HTTPS in production
3. Implement proper user management in production (database instead of hardcoded users)
4. Consider implementing token refresh mechanisms
5. Ensure the data directory is not publicly accessible
6. Validate file uploads
7. Implement rate limiting in production

## License

This project is licensed under the MIT License - see the LICENSE file for details.
