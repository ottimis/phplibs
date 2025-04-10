# ottimis/phplibs

A comprehensive PHP library of tools for developing modern and robust applications, designed to simplify the creation of RESTful APIs with a modular architecture.

## Table of Contents

- [Installation](#installation)
- [Main Components](#main-components)
  - [Database](#database)
  - [Utils](#utils)
  - [Logger](#logger)
  - [UUID](#uuid)
  - [Notify](#notify)
  - [OGMail](#ogmail)
  - [OGSmarty](#ogsmarty)
  - [OGHttp](#oghttp)
  - [RouteController](#routecontroller)
  - [Validator](#validator)
- [Enumerations](#enumerations)
- [Middleware](#middleware)
- [Usage Examples](#usage-examples)
- [Contributing](#contributing)
- [License](#license)

## Installation

```bash
composer require ottimis/phplibs
```

## Main Components

### Database

The `dataBase` class provides a simplified interface for MySQL database operations.

#### Features:

- Singleton pattern for connection management
- Methods for queries and transactions
- Support for value escaping

#### Available Methods:

```php
getInstance(string $dbname = ""): self           // Get singleton instance
createNew(string $dbname = ""): self             // Create new instance
close(): bool                                   // Close the connection
error(): string                                 // Get last error message
startTransaction(): void                        // Start a transaction
commitTransaction(): void                       // Commit current transaction
rollbackTransaction(): void                     // Rollback current transaction
query(string $sql): mysqli_result|bool         // Execute SQL query
multi_query(string $sql): mysqli_result|bool   // Execute multiple SQL queries
affectedRows(): int|string                      // Get number of affected rows
numrows(): int|string                           // Get number of rows in result
fetchobject(): object|false|null                // Fetch result as object
fetcharray(): false|array|null                  // Fetch result as array
fetchassoc(): array|false|null                  // Fetch result as associative array
freeresult(): void                              // Free result memory
real_escape_string($param): string              // Escape string for SQL
insert_id(): int|string                         // Get last insert ID
```

#### Usage Example:

```php
<?php
use ottimis\phplibs\dataBase;

// Get the singleton instance
$db = dataBase::getInstance();

// Execute a query
$result = $db->query("SELECT * FROM users");

// Retrieve results
while ($row = $db->fetchassoc()) {
    echo $row['username'];
}

// Use transactions
$db->startTransaction();
try {
    $db->query("INSERT INTO users (username) VALUES ('new_user')");
    $db->commitTransaction();
} catch (Exception $e) {
    $db->rollbackTransaction();
}
```

### Utils

The `Utils` class provides numerous utility methods, primarily for advanced database operations and pagination management.

#### Features:

- Query builder for SELECT, JOIN, WHERE
- Support for pagination and sorting
- UPSERT (INSERT/UPDATE) management
- Methods for image resizing

#### Available Methods:

```php
getInstance(string $dbname = ""): self                         // Get singleton instance
createNew(string $dbname = ""): self                           // Create new instance
startTransaction(): void                                      // Start a transaction
commitTransaction(): void                                     // Commit current transaction
rollbackTransaction(): void                                   // Rollback current transaction
upsert(UPSERT_MODE $mode, string $table, array $ar, array $fieldWhere = [], $noUpdate = false): array    // Insert or update records
select($req, $paging = array(), $sqlOnly = false): array|string   // Enhanced select with more features
```

#### Usage Example:

```php
<?php
use ottimis\phplibs\Utils;
use ottimis\phplibs\schemas\UPSERT_MODE;

$utils = new Utils();

// Advanced SELECT query with joins and filters
$result = $utils->select([
    "select" => ["u.id", "u.username", "p.name AS profile_name"],
    "from" => "users u",
    "join" => [
        [
            "fields" => ["p.name AS profile_name"]
            "table" => "profiles",
            "alias" => "p",
            "on" => ["id_profile"]
        ]
    ],
    "where" => [
        [
            "field" => "u.active",
            "value" => 1
        ]
    ],
    "order" => "u.username ASC"
]);

// Insert with UPSERT
$utils->upsert(
    UPSERT_MODE::INSERT,
    "users",
    [
        "username" => "johndoe",
        "email" => "john@example.com"
    ]
);

// Update with UPSERT
$utils->upsert(
    UPSERT_MODE::UPDATE,
    "users",
    [
        "email" => "john.updated@example.com"
    ],
    [
        "id" => 1
    ]
);
```

### Logger

The `Logger` class provides a versatile logging system with different levels and destinations.

#### Features:

- Logging modes: database, logstash, AWS CloudWatch, GELF
- Log levels: info, warning, error
- Singleton pattern for global access

#### Usage Example:

```php
<?php
use ottimis\phplibs\Logger;

// Get the logger instance
$logger = Logger::getInstance("my-service");

// Information logging
$logger->log("Operation completed successfully");

// Warning logging
$logger->warning("Warning: missing parameters", "MISSING_PARAM");

// Error logging
try {
    // Code that might throw exceptions
} catch (Exception $e) {
    $logger->error($e->getMessage(), "EXCEPTION", [
        "stacktrace" => $e->getTraceAsString()
    ]);
}
```

### UUID

The `UUID` class provides methods for generating UUIDs (Universally Unique Identifiers) according to RFC standards.

#### Features:

- UUID v3 generation (name-based with MD5)
- UUID v4 generation (random)
- UUID v5 generation (name-based with SHA1)
- UUID validation

#### Usage Example:

```php
<?php
use ottimis\phplibs\UUID;

// Generate a v4 UUID (random)
$uuid = UUID::v4();
echo $uuid; // Example: 550e8400-e29b-41d4-a716-446655440000

// Generate a v3 UUID (name-based with MD5)
$namespace = "550e8400-e29b-41d4-a716-446655440000";
$name = "example.com";
$uuid = UUID::v3($namespace, $name);

// Generate a v5 UUID (name-based with SHA1)
$uuid = UUID::v5($namespace, $name);

// Validate a UUID
if (UUID::is_valid($uuid)) {
    echo "Valid UUID";
}
```

### Notify

The `Notify` class allows sending notifications to an external service.

#### Features:

- Notification sending via cURL
- Configuration through environment variables

#### Usage Example:

```php
<?php
use ottimis\phplibs\Notify;

// Send a notification
Notify::notify(
    "Application error",
    [
        "message" => "An error occurred during processing",
        "code" => "ERR_PROCESSING"
    ],
    "user-service"
);
```

### OGMail

The `OGMail` class provides an interface for sending emails via SMTP or AWS SES.

#### Features:

- Support for attachments and inline images
- Templates with Smarty
- Support for multiple recipients, CC, BCC
- Email address validation

#### Available Methods:

```php
verify($email, $dns = false): bool                      // Verify email address format and optionally DNS
addRcpt($email): static                                // Add recipient
setReplyTo($email): static                             // Set reply-to address
addCc($email): static                                  // Add CC recipient
addBcc($email): static                                 // Add BCC recipient
setMailFrom($email): static                            // Set sender email
setMailFromName($name): static                         // Set sender name
setMailSubject($subject): static                       // Set email subject
setMailText($text): static                             // Set plain text content
setMailHtml($html): static                             // Set HTML content
addCid(CID $cid): static                               // Add inline image
addImage(CID $cid): static                             // Alias for addCid
addAttachment(Attach $attachment): static              // Add attachment
sendTemplate(?string $template = null, ?string $templateString = null, array $templateData = []): OGResponse    // Send email using template
send(): OGResponse                                     // Send email
sendPHPMailer(): OGResponse                            // Send using PHPMailer
sendAWS(): OGResponse                                  // Send using AWS SES
```

#### Usage Example:

```php
<?php
use ottimis\phplibs\OGMail;
use ottimis\phplibs\schemas\OGMail\Attach;
use ottimis\phplibs\schemas\OGMail\CID;

// Create an OGMail instance
$mail = new OGMail();

// Configure the email
$mail->setMailFrom("no-reply@example.com")
    ->setMailFromName("Example Service")
    ->setMailSubject("Important notification")
    ->setMailText("This is the text content of the email")
    ->setMailHtml("<h1>Important notification</h1><p>This is the HTML content of the email</p>")
    ->addRcpt("user@example.com")
    ->addCc("supervisor@example.com")
    ->addBcc("archive@example.com");

// Add an inline image
$mail->addCid(new CID("logo.png", "https://example.com/logo.png", "logo"));

// Add an attachment
$mail->addAttachment(new Attach("document.pdf", "/path/to/document.pdf"));

// Send the email
$result = $mail->send();

if ($result->success) {
    echo "Email sent successfully";
} else {
    echo "Error sending email: " . $result->errorMessage;
}

// Send an email using a Smarty template
$mail->sendTemplate(
    "welcome.tpl",
    null,
    [
        "username" => "John Doe",
        "activation_link" => "https://example.com/activate/123456"
    ]
);

// Validate an email address
if (OGMail::verify("user@example.com", true)) {
    echo "Valid email address";
}
```

### OGSmarty

The `OGSmarty` class provides a simplified wrapper for the Smarty template engine.

#### Features:

- Simplified configuration
- Easy template loading

#### Available Methods:

```php
__construct($smartyFolder = "/var/www/html/smarty")         // Constructor with folder path
loadTemplate($templateName = null, $templateString = null, $data = []): false|string    // Load and process template
```

#### Usage Example:

```php
<?php
use ottimis\phplibs\OGSmarty;

// Create an OGSmarty instance
$smarty = new OGSmarty("/path/to/smarty");

// Render a template with data
$html = $smarty->loadTemplate(
    "email/welcome.tpl",
    null,
    [
        "username" => "John Doe",
        "year" => date("Y")
    ]
);

// Render a string as a template
$templateString = "Hello {$username}, welcome to {$year}!";
$html = $smarty->loadTemplate(
    null,
    $templateString,
    [
        "username" => "John Doe",
        "year" => date("Y")
    ]
);
```

### OGHttp

The `OGHttp` class provides simplified methods for making HTTP requests.

#### Features:

- GET, POST, OPTIONS requests
- Support for Basic and JWT authentication
- Timeout and response handling

#### Available Methods:

```php
withBasicAuth($user, $pass): OGHttp                // Set Basic Authentication credentials
withJwt($jwt): OGHttp                             // Set JWT token for Authorization header
get($url): array                                  // Perform GET request
post($url, $ar = []): array                       // Perform POST request
options($url): array                              // Perform OPTIONS request
```

#### Usage Example:

```php
<?php
use ottimis\phplibs\OGHttp;

// Create an OGHttp instance
$http = new OGHttp();

// Make a GET request
$response = $http->get("https://api.example.com/users");
if ($response["statusCode"] == 200) {
    $body = json_decode($response["body"], true);
    print_r($body);
}

// Make a POST request with JWT authentication
$http->withJwt("your.jwt.token")
    ->post(
        "https://api.example.com/users",
        [
            "name" => "John Doe",
            "email" => "john@example.com"
        ]
    );

// Make a request with Basic Authentication
$response = $http->withBasicAuth("username", "password")
    ->get("https://api.example.com/protected-resource");
```

### RouteController

The `RouteController` class provides a system for mapping controllers and routes in Slim applications with an attribute-oriented approach.

#### Features:

- Automatic mapping of controller methods to routes
- Automatic middleware application
- Support for validation with validators
- Integration with OpenAPI for automatic documentation
- Simplified database query management with CRUD pattern

#### Usage Example:

```php
<?php
use ottimis\phplibs\RouteController;
use ottimis\phplibs\Middleware;
use ottimis\phplibs\Path;
use ottimis\phplibs\Methods;
use ottimis\phplibs\Method;
use ottimis\phplibs\Schema;
use Slim\Factory\AppFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class UserSchema {
    #[Validator(minLength: 3, maxLength: 50)]
    public string $username;
    
    #[Validator(format: VALIDATOR_FORMAT::EMAIL)]
    public string $email;
}

#[Middleware(["auth"])]
class UserController extends RouteController {
    protected string $tableName = "users";
    
    #[Schema(UserSchema::class)]
    public function _post(Request $request, Response $response) {
        $validatedData = $request->getAttribute('validatedBody');
        
        // User creation logic
        $result = $this->Utils->upsert(
            UPSERT_MODE::INSERT,
            $this->tableName,
            $validatedData
        );
        
        $response->getBody()->write(json_encode([
            "success" => $result['success'],
            "id" => $result['id'] ?? null
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    #[Path("/users/{id}")]
    #[Methods([Method::PUT])]
    public function _putUser(Request $request, Response $response, $args) {
        $id = $args['id'];
        $validatedData = $request->getAttribute('validatedBody');
        
        // User update logic
        $result = $this->Utils->upsert(
            UPSERT_MODE::UPDATE,
            $this->tableName,
            $validatedData,
            ["id" => $id]
        );
        
        $response->getBody()->write(json_encode(["success" => $result['success']]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    #[Middleware(["admin"])]
    public function _delete(Request $request, Response $response, $args) {
        $id = $args['id'];
        
        // User deletion logic
        $result = $this->delete($id);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function _get(Request $request, Response $response, $args) {
        $id = $args['id'];
        
        // Get user with relations
        $result = $this->get($id, [
            [
                "table" => "profiles",
                "alias" => "p",
                "on" => ["id_profile", "id"],
                "fields" => ["name", "bio"]
            ]
        ]);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

// In index.php
$app = AppFactory::create();

// Register middleware
RouteController::initializeMiddlewareRegistry([
    "auth" => function (Request $request, RequestHandlerInterface $handler) {
        // Authentication logic
        // ...
        return $handler->handle($request);
    },
    "admin" => function (Request $request, RequestHandlerInterface $handler) {
        // Admin authorization logic
        // ...
        return $handler->handle($request);
    }
]);

// Add global middleware
RouteController::addGlobalMiddlewares($app);

// Map the controller
RouteController::mapControllerRoutes($app, UserController::class, "/api/users");

$app->run();
```

### Validator

The `Validator` class provides a validation system based on PHP attributes.

#### Features:

- Validation attributes: required, minLength, maxLength, pattern, etc.
- Support for data types: string, integer, float, boolean, etc.
- Advanced validation with enum

#### Available Validator Parameters:

```php
#[Validator(
    required: bool,             // Whether the field is required (default: true)
    minLength: int,             // Minimum string length
    maxLength: int,             // Maximum string length
    pattern: string,            // Regular expression pattern
    format: VALIDATOR_FORMAT,   // Predefined format (e.g., DATE)
    type: VALIDATOR_TYPE,       // Data type (e.g., STRING, INTEGER, FLOAT)
    enum: array,                // List of allowed values
    enumType: string,           // Enum class implementing OGEnumValidatorInterface
    min: int,                   // Minimum numeric value
    max: int,                   // Maximum numeric value
    minDate: string,            // Minimum date
    maxDate: string,            // Maximum date
    multipleOf: string,         // Must be multiple of this value
    readOnly: bool              // Whether the field is read-only (default: false)
)]
```

#### Usage Example:

```php
<?php
use ottimis\phplibs\Validator;
use ottimis\phplibs\schemas\VALIDATOR_FORMAT;
use ottimis\phplibs\schemas\VALIDATOR_TYPE;
use ottimis\phplibs\Schema;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class UserRegistrationSchema {
    #[Validator(required: true, minLength: 3, maxLength: 50)]
    public string $username;
    
    #[Validator(required: true, pattern: '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/')]
    public string $email;
    
    #[Validator(required: true, minLength: 8)]
    public string $password;
    
    #[Validator(required: false, type: VALIDATOR_TYPE::INTEGER, min: 18, max: 120)]
    public ?int $age;
    
    #[Validator(required: false, format: VALIDATOR_FORMAT::DATE)]
    public ?string $birthdate;
    
    #[Validator(required: false, enum: ["admin", "user", "guest"])]
    public ?string $role;
}

// In a controller
class UserController extends RouteController {
    
    #[Schema(UserRegistrationSchema::class)]
    public function _post(Request $request, Response $response) {
        // Get validated data - automatic validation happens via the Schema attribute
        $validatedData = $request->getAttribute('validatedBody');
        
        // Process validated data...
        
        $response->getBody()->write(json_encode(["success" => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

## Enumerations

The library includes several enumerations to standardize values throughout the code:

### STATUS
```php
<?php
use ottimis\phplibs\schemas\STATUS;

$status = STATUS::ACTIVE; // 1
$status = STATUS::CANCELLED; // 2
```

### UPSERT_MODE
```php
<?php
use ottimis\phplibs\schemas\UPSERT_MODE;

$mode = UPSERT_MODE::INSERT;
$mode = UPSERT_MODE::UPDATE;
```

### VALIDATOR_FORMAT
```php
<?php
use ottimis\phplibs\schemas\VALIDATOR_FORMAT;

$format = VALIDATOR_FORMAT::DATE; // 'date'
```

### VALIDATOR_TYPE
```php
<?php
use ottimis\phplibs\schemas\VALIDATOR_TYPE;

$type = VALIDATOR_TYPE::STRING; // 'string'
$type = VALIDATOR_TYPE::INTEGER; // 'integer'
$type = VALIDATOR_TYPE::FLOAT; // 'float'
// etc.
```

## Middleware

The library includes a validation middleware that can be used with the Slim framework:

## Usage Examples

### Authentication System

```php
<?php
use ottimis\phplibs\dataBase;
use ottimis\phplibs\UUID;
use ottimis\phplibs\Logger;

class Auth {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = dataBase::getInstance();
        $this->logger = Logger::getInstance("auth-service");
    }
    
    public function login($username, $password) {
        $username = $this->db->real_escape_string($username);
        $result = $this->db->query("SELECT * FROM users WHERE username = '$username'");
        
        if ($this->db->numrows() == 0) {
            $this->logger->log("Login failed: username not found", "AUTH_FAIL");
            return false;
        }
        
        $user = $this->db->fetchobject();
        
        if (!password_verify($password, $user->password)) {
            $this->logger->log("Login failed: wrong password", "AUTH_FAIL");
            return false;
        }
        
        // Generate a session token
        $sessionId = UUID::v4();
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $this->db->query("INSERT INTO sessions (id, user_id, expiry) VALUES ('$sessionId', $user->id, '$expiry')");
        
        $this->logger->log("Login successful for user $username", "AUTH_SUCCESS");
        
        return [
            "user" => $user,
            "session_id" => $sessionId,
            "expiry" => $expiry
        ];
    }
}
```

### Transactional Email System

```php
<?php
use ottimis\phplibs\OGMail;
use ottimis\phplibs\OGSmarty;
use ottimis\phplibs\Logger;
use ottimis\phplibs\schemas\OGMail\CID;
use ottimis\phplibs\schemas\OGMail\Attach;

class EmailService {
    private $mail;
    private $smarty;
    private $logger;
    
    public function __construct() {
        $this->mail = new OGMail();
        $this->smarty = new OGSmarty();
        $this->logger = Logger::getInstance("email-service");
    }
    
    public function sendWelcomeEmail($user) {
        try {
            $activationCode = bin2hex(random_bytes(16));
            
            // Store activation code in database (example)
            $db = dataBase::getInstance();
            $userId = $db->real_escape_string($user['id']);
            $code = $db->real_escape_string($activationCode);
            $db->query("INSERT INTO activation_codes (user_id, code) VALUES ('$userId', '$code')");
            
            // Generate activation link
            $activationLink = "https://example.com/activate/" . $activationCode;
            
            // Load HTML template
            $html = $this->smarty->loadTemplate(
                "emails/welcome.tpl",
                null,
                [
                    "name" => $user['first_name'],
                    "activation_link" => $activationLink
                ]
            );
            
            // Add company logo as inline image
            $logo = new CID(
                "logo.png",
                "https://example.com/assets/logo.png",
                "company-logo"
            );
            
            // Configure and send the email
            $result = $this->mail
                ->setMailFrom(getenv("MAIL_FROM"))
                ->setMailFromName(getenv("MAIL_FROM_NAME"))
                ->setMailSubject("Welcome to Example Service")
                ->setMailHtml($html)
                ->addRcpt($user['email'])
                ->addCid($logo)
                ->send();
                
            if ($result->success) {
                $this->logger->log("Welcome email sent to {$user['email']}", "EMAIL_SENT");
                return true;
            } else {
                $this->logger->error("Error sending welcome email: " . $result->errorMessage, "EMAIL_ERROR");
                return false;
            }
        } catch (Exception $e) {
            $this->logger->error("Exception sending email: " . $e->getMessage(), "EMAIL_EXCEPTION");
            return false;
        }
    }
    
    public function sendPasswordResetEmail($user) {
        // Generate reset token
        $resetToken = bin2hex(random_bytes(16));
        
        // Store reset token in database
        $db = dataBase::getInstance();
        $userId = $db->real_escape_string($user['id']);
        $token = $db->real_escape_string($resetToken);
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $db->query("INSERT INTO password_reset_tokens (user_id, token, expiry) VALUES ('$userId', '$token', '$expiry')");
        
        // Reset link
        $resetLink = "https://example.com/reset-password/" . $resetToken;
        
        // Email template data
        $templateData = [
            "name" => $user['first_name'],
            "reset_link" => $resetLink,
            "expiry_time" => "1 hour"
        ];
        
        // Send the password reset email
        $result = $this->mail
            ->setMailFrom(getenv("MAIL_FROM"))
            ->setMailFromName(getenv("MAIL_FROM_NAME"))
            ->setMailSubject("Password Reset Request")
            ->sendTemplate("emails/password-reset.tpl", null, $templateData);
            
        return $result->success;
    }
}
```

### Advanced Query Builder with Pagination

```php
<?php
use ottimis\phplibs\Utils;
use ottimis\phplibs\schemas\STATUS;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class ProductController extends RouteController {
    protected string $tableName = "products";
    
    #[Path('/products')]
    #[Middleware(['auth'])]
    public function _getList(Request $request, Response $response): Response {
        $utils = new Utils();
        
        // Get query parameters for paging, filtering, and sorting
        $queryParams = $request->getQueryParams();
        
        // Set up pagination parameters
        $paging = [
            "p" => $queryParams['page'] ?? 1,         // Current page
            "c" => $queryParams['per_page'] ?? 20,    // Items per page
            "s" => $queryParams['search'] ?? "",      // Search text
            "srt" => $queryParams['sort'] ?? "name",  // Sort field
            "o" => $queryParams['order'] ?? "ASC",    // Sort direction (ASC or DESC)
            
            // Define searchable fields (for text search)
            "searchableFields" => [
                "p.name",
                "p.description",
                "c.name"
            ],
            
            // Define filterable fields (exact match)
            "filterableFields" => [
                "p.id_category",
                "p.id_status"
            ]
        ];
        
        // Build advanced query with joins and conditions
        $result = $utils->select([
            "select" => [
                "p.id",
                "p.name",
                "p.description",
                "p.price",
                "p.stock",
                "c.name AS category_name",
                "p.created_at"
            ],
            "from" => "products p",
            "join" => [
                [
                    "table" => "categories c",
                    "alias" => "c",
                    "on" => ["id_category", "id"]
                ]
            ],
            "where" => [
                [
                    "field" => "p.id_status",
                    "value" => STATUS::ACTIVE->value
                ],
                // Add price range filter if provided
                isset($queryParams['min_price']) ? [
                    "field" => "p.price",
                    "operator" => ">=", 
                    "value" => $queryParams['min_price']
                ] : null,
                isset($queryParams['max_price']) ? [
                    "field" => "p.price",
                    "operator" => "<=",
                    "value" => $queryParams['max_price']
                ] : null
            ],
            // Return total record count
            "count" => true
        ], $paging);
        
        // Format response with pagination metadata
        $response->getBody()->write(json_encode([
            "data" => $result['rows'],
            "pagination" => [
                "total" => $result['total'],
                "count" => $result['count'],
                "per_page" => (int)$paging['c'],
                "current_page" => (int)$paging['p'],
                "total_pages" => ceil($result['total'] / $paging['c'])
            ]
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    #[Path('/products/category/{categoryId}')]
    public function _getByCategory(Request $request, Response $response, $args): Response {
        $categoryId = $args['categoryId'];
        $utils = new Utils();
        
        // Get products by category with nested data
        $result = $utils->select([
            "select" => [
                "p.*",
                "c.name AS category_name"
            ],
            "from" => "products p",
            "join" => [
                [
                    "table" => "categories c",
                    "alias" => "c",
                    "on" => ["id_category", "id"]
                ]
            ],
            "where" => [
                [
                    "field" => "p.id_category",
                    "value" => $categoryId
                ],
                [
                    "field" => "p.id_status",
                    "value" => STATUS::ACTIVE->value
                ]
            ],
            // Get product images
            "decode" => ["p.images"] // JSON decode the 'images' field
        ]);
        
        // Get product reviews separately and merge
        foreach ($result['data'] as &$product) {
            $reviewsResult = $utils->select([
                "select" => ["r.*", "u.name AS user_name"],
                "from" => "reviews r",
                "join" => [
                    [
                        "table" => "users u",
                        "alias" => "u",
                        "on" => ["id_user", "id"]
                    ]
                ],
                "where" => [
                    [
                        "field" => "r.id_product",
                        "value" => $product['id']
                    ]
                ],
                "order" => "r.created_at DESC"
            ]);
            
            $product['reviews'] = $reviewsResult['data'] ?? [];
            $product['rating_avg'] = !empty($product['reviews']) ? 
                array_sum(array_column($product['reviews'], 'rating')) / count($product['reviews']) : 
                0;
        }
        
        $response->getBody()->write(json_encode($result['data']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

## Contributing

If you'd like to contribute to the development of the library, you can do so through the GitHub repository:

1. Fork the repository
2. Create a branch for your modification (`git checkout -b feature/new-feature`)
3. Commit your changes (`git commit -am 'Added new feature'`)
4. Push the branch (`git push origin feature/new-feature`)
5. Open a Pull Request

## License

This library is released under the MIT license.
