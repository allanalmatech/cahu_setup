<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\Response;

final class HomeController extends Controller
{
    public function index(): Response
    {
        // If the user is already authenticated, send them to the dashboard
        if ($this->auth->check()) {
            return $this->redirect(url('dashboard'));
        }

        // home.php is a complete standalone document, so it is rendered with no
        // layout wrapper. An empty layout string means "content only".
        return $this->render('home', [], '');
    }
}
