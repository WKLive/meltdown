<?php
require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

$app = new Silex\Application();

/* - Pick out the JSON - */
$app->before(function (Request $request){
    if (0 === strpos($request->headers->get('content-type'), 'application/json')) {
      file_put_contents("/tmp/fullog", print_r($request->getContent(), true));
      $data = json_decode($request->getContent(), true);
      $request->request->replace(is_array($data) ? $data : array());
    }
});

/*
  Issue objects should look something like this:

  ID   - autoincr int
  who  - string
  what - longer string.
  Open - Boolean
*/

/* POSTs */
/* New status post */
$app->post('/', function(Request $req) use ($app) {
    // Arguments
    $who = $req->request->get('who');
    $what = $req->request->get('what');

    if(empty($who) or empty($what)) {
      return $app->json(array('success' => false,
                              'why' => 'To few arguments'), 418);
    }

    $mongo = new \Mongo();
    $db = $mongo->meltdown;

    // Generate next id
    $uid_obj = $db->variables->findOne(array('name' => 'uid_counter'), array('value' => -1));
    $uid = !empty($uid_obj) && key_exists('value', $uid_obj) ? $uid_obj['value'] +1 : 0;
    $db->variables->remove(array('name' => 'uid_counter'));
    $db->variables->insert(array('name' => 'uid_counter', 'value' => $uid));

    $db->issues->insert(array(
                            '_id' => $uid,
                            'who' => strip_tags($who),
                            'what' => strip_tags($what),
                            'open' => true));

    return $app->json(array('success' => true, 'id' => $uid), 201);
});


/**
 * PUTs
 * Are used for updating or closing issues
 */
$app->put('/{id}/close', function($id) use ($app) {
    if(!(is_numeric($id) && is_int((int)$id))) {
      return $app->json(array('success' => false, 'why' => "Issue ID must be an INT"), 418);
    }

    $mongo = new \Mongo();
    $db = $mongo->meltdown;

    // db.issues.update({'_id': 1},{$set: {'open': false}});
    $db->issues->update(array('_id' => (int)$id), array('$set' => array('open' => false)));
    $issue = $db->issues->findOne(array('_id' => (int)$id));

    if(isset($issue) && !$issue['open']) { // add isset $issue
      return $app->json(array('success' => true, 'open' => $issue['open'], 'id' => $issue['_id']), 200);
    }

    return $app->json(array('success' => false, 'why' => "Update failed"), 500);
});

/* GETs */
$app->get('/', function(Request $req) use ($app) {
    $mongo = new \Mongo();
    $db = $mongo->meltdown;
    $open_issues = $db->issues->find(array('open' => true));

    if (0 === strpos($req->headers->get('content-type'), 'application/json')) {
      $ret = array();
      foreach($open_issues as $issue) {
        $ret[]= array(
          'id' => $issue['_id'],
          'who' => $issue['who'],
          'what' => $issue['what'],
          'open' => $issue['open']);
      }

      return $app->json($ret);
    }
    else {
        $ret = "Open issues:\n";

        foreach($open_issues as $issue) {
          $ret .= "[". $issue['_id'] . "] who: " . $issue['who'] . ", what: " . $issue['what'] . "\n";
        }

        return new Response($ret, 200, array('Content-Type' => 'text/plain'));
    }
});


$app->run();
