<?php

namespace App\Services;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

class TwigCompileService
{
    public static function compile($value, array $args = array()) {
        $loader = new ArrayLoader(array(
            'template.html' => $value,
        ));
        $twig = new Environment($loader);

        return $twig->render('template.html', $args);
    }
}
