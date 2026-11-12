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
foreach ([1 => ['sdr_member'], 2 => ['sdr_organizer'], 3 => ['administrator'], 4 => ['administrator'], 5 => ['sdr_member'], 6 => ['sdr_member', 'administrator'], 7 => ['sdr_member'], 8 => ['sdr_member']] as $id => $roles) {
    $GLOBALS['members'][] = (object) ['ID' => $id, 'roles' => $roles];
}
$GLOBALS['member_meta'] = [3 => ['sdr_roles' => ['joueur']], 5 => ['sdr_demo' => true]];
foreach (range(1, 6) as $id) $GLOBALS['member_meta'][$id]['sdr_stage_opt_in'] = true;
$GLOBALS['member_meta'][7]['sdr_stage_opt_in'] = false;
$ids = array_column(SDR_Coulisses::planning_members(), 'ID');
$assert($ids, [1, 2, 3, 6], 'Seuls les membres OK pour la scène sont attendus ; démonstration, administration seule, refus et préférence absente exclus');
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
$GLOBALS['member_meta'][6]['sdr_stage_opt_in'] = false;
$opted_in = array_column(SDR_Coulisses::planning_members(), 'ID');
$assert(SDR_Coulisses::response_progress($responses, $opted_in), ['total' => 3, 'pending' => 0], 'Décocher OK pour la scène retire une réponse attendue');
$GLOBALS['member_meta'][3]['sdr_stage_opt_in'] = false;
$opted_in = array_column(SDR_Coulisses::planning_members(), 'ID');
$assert(SDR_Coulisses::response_progress($responses, $opted_in), ['total' => 3, 'pending' => 0], 'Réponse indisponible conservée après retrait de OK pour la scène');
$assert(SDR_Coulisses::response_progress([...$responses, ['userId' => 7, 'availability' => 'yes', 'roles' => ['mc']]], $opted_in), ['total' => 4, 'pending' => 0], 'Réponse volontaire hors des OK pour la scène conservée sans gonfler les sans réponse');
$assert(SDR_Coulisses::response_progress(array_slice($responses, 0, 2), $opted_in), ['total' => 2, 'pending' => 0], 'Retirer une réponse hors des OK pour la scène ne crée pas de réponse attendue');
echo "$checks vérifications réussies. Aucune base de données ni email.\n";
