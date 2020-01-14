# Ottimis phplibs

Descrizione... 

<!-- ## Getting Started -->

<!-- These instructions will get you a copy of the project up and running on your local machine for development and testing purposes. See deployment for notes on how to deploy the project on a live system. -->

## OAuth2.0

### Getting Started

Dopo aver importato tutto e predisposto un index.php con Slim Framework è sufficente inizializzare un nuovo oggetto OAuth2, aggiungere i GrantType necessari e chiamare la funzione api che genererà i vari endpoint.

```
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy as RouteCollectorProxy;
use Slim\Exception\HttpNotFoundException as HttpNotFoundException;
use Slim\Factory\AppFactory;
use \ottimis\phplibs\OAuth2;

$app = AppFactory::create();
$oauth = new OAuth2();
$oauth->addGrantType($oauth::CLIENT_CREDENTIAL);

$oauth->api($app);
```

### Endpoint

Tutti gli endpoint sono nel gruppo /oauth2
        
Un esempio di chiamata **authorize** è il seguente:

```
http://localhost/oauth2/authorize?response_type=code&client_id=testclient&state=xyz
```

Questa chiamata restituirà al return uri il **code** necessario per la richiesta del *token* che verrà effettuata con una post:

```
curl -u testclient:testpass http://localhost/oauth2/token -d 'grant_type=authorization_code&code=YOUR_CODE'
```

Una volta avuto il token potrà essere chiamato l'endpoint per la verifica del token e dello scope:

```
curl http://localhost/oauth2/verify -d 'access_token=YOUR_TOKEN'
```

<!-- ### Installing

A step by step series of examples that tell you how to get a development env running

Say what the step will be

```
Give the example
```

And repeat

```
until finished
```

End with an example of getting some data out of the system or using it for a little demo

## Running the tests

Explain how to run the automated tests for this system

### Break down into end to end tests

Explain what these tests test and why

```
Give an example
```

### And coding style tests

Explain what these tests test and why

```
Give an example
```

## Deployment

Add additional notes about how to deploy this on a live system -->

## Built With

* [OAuth 2.0 Server PHP by bshaffer](https://bshaffer.github.io/oauth2-server-php-docs) - OAuth 2 library
* [Slim Framework 4](https://www.slimframework.com/) - Api libs


## Logger

### Getting Started

Creare le tabelle necessarie alla libreria con la seguente query SQL ed importare la libreria con composer

```
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for logs
-- ----------------------------
DROP TABLE IF EXISTS `logs`;
CREATE TABLE `logs` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`type` int(11) DEFAULT NULL,
`stacktrace` text,
`note` text,
`code` varchar(10) DEFAULT NULL,
`datetime` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5397 DEFAULT CHARSET=latin1;

SET FOREIGN_KEY_CHECKS = 1;

<--------------------log_types------------------------------>

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for log_types
-- ----------------------------
DROP TABLE IF EXISTS `log_types`;
CREATE TABLE `log_types` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`log_type` varchar(15) DEFAULT NULL,
`color` varchar(7) DEFAULT NULL,
PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1;

-- ----------------------------
-- Records of log_types
-- ----------------------------
BEGIN;
INSERT INTO `log_types` VALUES (1, 'Log', '#259d00');
INSERT INTO `log_types` VALUES (2, 'Warning', '#d8a00d');
INSERT INTO `log_types` VALUES (3, 'Error', '#d81304');
COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
```
```
use \ottimis\phplibs\OAuth2;
```

<!-- ## Contributing

Please read [CONTRIBUTING.md](https://gist.github.com/PurpleBooth/b24679402957c63ec426) for details on our code of conduct, and the process for submitting pull requests to us. -->

<!-- ## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/your/project/tags).  -->

## Authors

* **Ottimis Group** 
<!-- - *Initial work* - [PurpleBooth](https://github.com/PurpleBooth) -->

<!-- See also the list of [contributors](https://github.com/your/project/contributors) who participated in this project. -->

<!-- ## License -->

<!-- This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details -->

<!-- ## Acknowledgments -->

<!-- * Hat tip to anyone whose code was used
* Inspiration
* etc -->

