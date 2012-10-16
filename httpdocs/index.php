<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/highlight.php';

/**
 * We don't need no stinking architecture!1!!
 * 
 * Seriously though, Silex was an obvious choice as the website is quite simple
 * at the moment. We might want to migrate to something else when the website gets
 * bigger, but for now, this works like a charm!
 */
$config = require __DIR__ . '/../config.php';

$db = new Pdo(
	$config['database']['dsn'],
	$config['database']['username'],
	$config['database']['password']
);

$db->setAttribute (PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute (PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/**
 * Gets the recent pastes from the database.
 * 
 * @param PDO $db
 * @return array
 */
$getRecent = function ($db) {
	$stmt = $db->query( 
		'SELECT paste_id, name, added FROM paste ORDER BY added DESC LIMIT 5 OFFSET 0'
	);

	$stmt->execute();
	$recent = $stmt->fetchAll();
	return $recent !== false ? $recent : array();
};

/**
 * Gets the details of a paste from the database.
 * 
 * @param PDO $db
 * @param string $id
 * @return array
 */
$getPaste = function ($db, $id) {
	$stmt = $db->prepare( 'SELECT * FROM paste WHERE paste_id = :id' );
	$stmt->execute( array( 'id' => $id ) );

	return $stmt->fetch( );
};

/**
 * Build the application, and set-up twig integration.
 */
$app = new Silex\Application();

$app->register (new Silex\Provider\TwigServiceProvider (), array (
    'twig.path' => __DIR__.'/../views',
));

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) use( $config ) {
	if( isset( $config['debug'] ) && $config['debug'] == true ) {
		$twig->addFilter('var_dump',new \Twig_Filter_Function('var_dump'));
		$app['debug'] = true;
	}
	$twig->addFilter('highlight', new \Twig_Filter_Function( 'highlightSaffire' ) );
    return $twig;
}));

/**
 * Display the homepage.
 * 
 * @todo We might want to reconsider how we do highlighting: maybe it would be 
 * easier to do on the fly?
 */
$app->get ('/', function () use ($app, $db, $getRecent) {
	return $app['twig']->render( 'home.html.twig', array(
		'recent' => $getRecent ($db),
		'name' => isset ($_COOKIE['name']) ? $_COOKIE['name'] : ''
	));
});

/**
 * Display the codepad.
 */
$app->get ('/codepad', function () use ($app, $db, $getRecent) {
	return $app['twig']->render( 'new-paste.html.twig', array(
		'recent' => $getRecent ($db),
		'name' => isset ($_COOKIE['name']) ? $_COOKIE['name'] : ''
	));
});

/**
 * Display a specific paste in the codepad.
 */
$app->get ('/codepad/{id}', function ($id) use ($app, $db, $getRecent, $getPaste) {
	$paste = $getPaste($db, $id);
	if( $paste === false ) {
		$app->abort( 404, 'Paste does not exist' );
	}

	return $app['twig']->render( 'paste.html.twig', array(
		'recent' => $getRecent($db),
		'paste' => $paste,
		'name' => isset( $_COOKIE['name'] ) ? $_COOKIE['name'] : ''
	));
})->assert('id', '[^.]+');

$app->post ('/codepad', function () use ($app, $db, $config) {
	/**
	 * Write the content to a temporary file, execute it
	 * and delete the temporary file.
	 */
	$tmpfile = '/tmp/saffire.codepad.' . posix_getpid();
	$dotfile = '/tmp/saffire.dotfile.' . posix_getpid();
	$pngfile = '/tmp/saffire.pngfile.' . posix_getpid();

	file_put_contents ($tmpfile, $_POST['paste']);

	$command = sprintf ('%s %s 2>&1 | grep -v "Reduce at line"', $config['binary'], $tmpfile);
	$output = shell_exec( $command );

	if (stripos ($output, 'Error at line') === false) {
		$command = sprintf (
			'%1$s %2$s --dot %3$s && dot %3$s -Tpng > %4$s && echo $?',
			$config['binary'], 
			$tmpfile, 
			$dotfile, 
			$pngfile 
		);
		$dotoutput = shell_exec ($command);
	
		if (substr (trim($dotoutput), -1) === '0'){
			$context = stream_context_create (array (
				'http' => array (
					'method'  => 'POST',
					'header' => "Content-type: application/x-www-form-urlencoded\r\n",
					'content' => http_build_query (
						array (
							'image' => base64_encode (file_get_contents ($pngfile)),
							'key' => $config['imgur_key']
						)
					),
					'timeout' => 5,
				)
			));

			$imgur = file_get_contents ('http://api.imgur.com/2/upload.json', false, $context);
			$response = json_decode ($imgur);

			if( isset ($response->upload->links->original)) {
				$image = $response->upload->links->original; 
			}
		}
	}
	unlink( $tmpfile, $dotfile, $pngfile );

	/**
	 * Added the name used to a cookie for future use.
	 */
	setcookie( 'name', $_POST['name'], time() + 60 * 60 * 24 * 30 );

	/**
	 * Insert the paste to the database.
	 */
	$insert = $db->prepare( 
		'INSERT INTO paste ( paste_id, paste, name, added, output, private, image ) VALUES ( :paste_id, :paste, :name, NOW(), :output, :private, :image );'
	);

	$result = $insert->execute(array(
		'paste_id' => ( $paste_id = uniqid( ) ),
		'paste' => $_POST['paste'],
		'name' => $_POST['name'],
		'output' => $output,
		'private' => isset( $_POST['private'] ) && $_POST['private'] == 'yes' ? '0' : '1',
		'image' => isset( $image ) ? $image : ''
	));
	
	if( $result !== false ) {
		return $app->redirect( '/codepad/' . $paste_id );
	}	
});

$app->run();