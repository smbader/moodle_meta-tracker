<?php
namespace App\Behat;

use Behat\Behat\Context\Context;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class FeatureContext implements Context
{
    private $client;
    private $baseUrl = 'http://localhost';
    private $response;
    private $cookieJar;

    public function __construct()
    {
        $this->cookieJar = new CookieJar();
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'cookies' => $this->cookieJar,
            'http_errors' => false
        ]);
    }

    /**
     * @Given I am on :path
     */
    public function iAmOn($path)
    {
        $this->response = $this->client->get($path);
    }

    /**
     * @When I submit the registration form with username :username and password :password
     */
    public function iSubmitTheRegistrationForm($username, $password)
    {
        $this->response = $this->client->post('/register.php', [
            'form_params' => [
                'username' => $username,
                'password' => $password,
                'submit' => 'Register'
            ]
        ]);
    }

    /**
     * @When I submit the login form with username :username and password :password
     */
    public function iSubmitTheLoginForm($username, $password)
    {
        $this->response = $this->client->post('/login.php', [
            'form_params' => [
                'username' => $username,
                'password' => $password,
                'submit' => 'Login'
            ]
        ]);
    }

    /**
     * @Then I should see :text
     */
    public function iShouldSee($text)
    {
        $body = (string) $this->response->getBody();
        if (strpos($body, $text) === false) {
            throw new \Exception("Text '$text' not found in response");
        }
    }

    /**
     * @Given I am logged in as :username with password :password
     */
    public function iAmLoggedInAsWithPassword($username, $password)
    {
        $this->iSubmitTheLoginForm($username, $password);
    }

    /**
     * @When I go to :path
     */
    public function iGoTo($path)
    {
        $this->iAmOn($path);
    }
}
