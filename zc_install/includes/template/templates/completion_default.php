<?php
/**
 * @package Installer
 * @copyright Copyright 2003-2014 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id:
 */

function ngxconf_update($fname, $oldword, $newword) {
	$fhandle = fopen($fname,"r");
	$content = fread($fhandle,filesize($fname));
	
	$content = str_replace($oldword, $newword, $content);
	
	$fhandle = fopen($fname,"w");
	fwrite($fhandle,$content);
	fclose($fhandle);
}
$target_file = "includes/nginx_conf/ngx_conf_server.txt";
$main_dir = "/" . trim($_POST['dir_ws_http_catalog'],"/");
$admin_dir = trim($_POST['admin_directory'],"/");
ngxconf_update($target_file, '%%http_store_folder%%', $main_dir);
ngxconf_update($target_file, '%%admin_folder%%', $admin_dir);

require(DIR_FS_INSTALL . DIR_WS_INSTALL_TEMPLATE . 'partials/partial_modal_help.php');
?>


<div align="center" class="alert-box success">
	<h5><font color="white">
	
<?php if ($isUpgrade) { ?>
	<?php echo TEXT_COMPLETION_UPGRADE_COMPLETE; ?>
<?php } else { ?>
	<?php echo TEXT_COMPLETION_INSTALL_COMPLETE; ?>
	<br>
	<?php if ($catalogLink != '#') echo TEXT_COMPLETION_INSTALL_LINKS_BELOW; ?>
<?php } ?>
	</font></h5>
	<br>
	<div align="center" class="showModal button warning radius" id="NGINXCONF">
		<h6><?php echo TEXT_COMPLETION_NGINX_TEXT; ?></h6>
	</div>
<?php if (!$isUpgrade && $catalogLink != '#') { ?>
	<br><br>
	<div align="center">
		<a class="radius button" href="<?php echo $adminLink; ?>" target="_blank" tabindex="1">
			<?php echo TEXT_COMPLETION_ADMIN_LINK_TEXT; ?>:<br><br>
			<u><?php echo $adminLink; ?></u>
		</a>
		<a class="radius button" href="<?php echo $catalogLink; ?>" target="_blank" tabindex="2">
			<?php echo TEXT_COMPLETION_CATALOG_LINK_TEXT; ?>:<br><br>
			<u><?php echo $catalogLink; ?></u>
		</a>
	</div>
<?php } ?>

<?php if ($_POST['admin_directory'] == 'admin' && !defined('DEVELOPER_MODE')) { ?>
<br><br>
<div class="alert-box  secondary">
	<h6><?php echo TEXT_COMPLETION_ADMIN_DIRECTORY_WARNING; ?></h6>
</div>
<br>
<?php } ?>
<?php if (file_exists(DIR_FS_INSTALL) && !defined('DEVELOPER_MODE')) { ?>
<br>
<div class="alert-box  secondary">
	<h6><?php echo TEXT_COMPLETION_INSTALLATION_DIRECTORY_WARNING; ?></h6>
	<h6><?php echo TEXT_COMPLETION_INSTALLATION_DIRECTORY_EXPLANATION; ?></h6>
</div>
<br>
<?php } ?>


</div>



<script>
$(function()
    {
      $('.showModal').click(function(e)
      {
        var textId = $(this).attr('id');
        $.ajax({
          type: "POST",
           timeout: 100000,
          dataType: "json",
          data: 'id='+textId,
          url: '<?php echo "ajaxGetHelpText.php"; ?>',
           success: function(data) {
             $('#modal-help-title').html(data.title);
             $('#modal-help-content').html(data.text);
             $('#modal-help').foundation('reveal', 'open');
          }
        });
        e.preventDefault();
      })
    });
</script>
