<?php /** Delegates to the shared error screen. */ ?>
<?php echo $view->capture('errors/error', ['status' => 500, 'message' => $message]); ?>
