<?php
/**
 * Beacon JS template.
 *
 * @param array $data {
 *      @type string $form_id  Beacon form ID.
 *      @type string $identify Identify data to send to Helpscout.
 *      @type string $session  Session data to send to Helpscout.
 *      @type string $prefill  Prefill data to send to Helpscout.
 * }
 */

defined( 'ABSPATH' ) || exit;

?>

<script type="text/javascript">!function(e,t,n){function a(){var e=t.getElementsByTagName("script")[0],n=t.createElement("script");n.type="text/javascript",n.async=!0,n.src="https://beacon-v2.helpscout.net",e.parentNode.insertBefore(n,e)}if(e.Beacon=n=function(t,n,a){e.Beacon.readyQueue.push({method:t,options:n,data:a})},n.readyQueue=[],"complete"===t.readyState)return a();e.attachEvent?e.attachEvent("onload",a):e.addEventListener("load",a,!1)}(window,document,window.Beacon||function(){});
window.Beacon('init', '834ab8f3-505b-4f6c-bdf8-c5b3f73c48ae')
window.Beacon("identify", <?php echo $data['identify']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>);
window.Beacon("session-data", <?php echo $data['session']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>);
</script>
