<?php

namespace App;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Validates the .env file for required attributes
 *
 * Class DotenvValidator
 * @package App
 */
class DotenvValidator
{
    /**
     * @var \Symfony\Component\Dotenv\Dotenv
     */
    private $dotenv;

    /**
     * DotenvValidator constructor.
     *
     * @param \Symfony\Component\Dotenv\Dotenv $dotenv
     */
    public function __construct(Dotenv $dotenv)
    {
        $this->dotenv = $dotenv;
    }

    /**
     * Check for required values
     *
     * @param array $requiredVars
     *
     * @return void
     *
     * @throws \Exception
     */
    public function require(array $requiredVars): void
    {
        foreach ($requiredVars as $var) {
            if (empty(getenv($var))) {
                throw new \Exception(".env is missing the required `$var` key");
            }
        }
    }
}