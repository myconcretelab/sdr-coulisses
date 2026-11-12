<?php
/** php tests/response-progress.php — isolated fixtures, no database or emails. */
define('ABSPATH', __DIR__.'/');
function add_action(...$args) {}
function add_filter(...$args) {}
function add_shortcode(...$args) {}
function register_activation_hook(...$args) {}
function get_users($query) { return $GLOBALS['members']; }
function get_user_meta($id, $key, $single = true) { return $GLOBALS['member_meta'][$id][$key] ?? ''; }
require dirname(__DIR__).'/sdr-coulisses.php';

$checks = 0;
$assert = static function($actual, $expected, $message) use (&$checks) {
    if ($actual !== $expected) throw new RuntimeException($message.' : '.json_encode($actual));
    $checks++;
};
$GLOBALS['members'] = [];
foreach ([1 => ['sdr_member'], 2 => ['sdr_organizer'], 3 => ['administrator'], 4 => ['administrator'], 5 => ['sdr_member'], 6 => ['sdr_member', 'administrator']] as $id => $roles) {
    $GLOBALS['members'][] = (object) ['ID' => $id, 'roles' => $roles];
}
$GLOBALS['member_meta'] = [3 => ['sdr_roles' => ['joueur']], 5 => ['sdr_demo' => true]];
$ids = array_column(SDR_Coulisses::planning_members(), 'ID');
$assert($ids, [1, 2, 3, 6], 'Membres et organisation, administrateur artiste et multirôle inclus ; démonstration et administration seule exclues');
$responses = [
    ['userId' => 1, 'availability' => 'yes', 'roles' => ['joueur', 'mc']],
    ['userId' => 2, 'availability' => 'maybe', 'roles' => ['dj']],
    ['userId' => 3, 'availability' => 'no', 'roles' => []],
];
$assert(SDR_Coulisses::response_progress($responses, $ids), ['total' => 4, 'pending' => 1], 'Toutes les disponibilités comptent une seule fois');
$assert(SDR_Coulisses::response_progress([], $ids), ['total' => 4, 'pending' => 4], 'Aucune réponse');
$assert(SDR_Coulisses::response_progress([], []), ['total' => 0, 'pending' => 0], 'Troupe vide');
$assert(SDR_Coulisses::response_progress($responses, [1, 2, 3]), ['total' => 3, 'pending' => 0], 'Tout le monde a répondu');
$assert(SDR_Coulisses::response_progress($responses, [1, 2]), ['total' => 3, 'pending' => 0], 'Ancien membre ayant répondu conservé dans le total');
$assert(SDR_Coulisses::response_progress(array_slice($responses, 1), $ids), ['total' => 4, 'pending' => 2], 'Retirer sa réponse rétablit une personne sans réponse');
$assert(SDR_Coulisses::response_progress([...$responses, $responses[0]], [1, '1', 2, 3]), ['total' => 3, 'pending' => 0], 'Identifiants uniques et normalisés');
echo "$checks vérifications réussies. Aucune base de données ni email.\n";
