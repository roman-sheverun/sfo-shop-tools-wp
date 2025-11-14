<?php

defined('ABSPATH') || defined('DUPXABSPATH') || exit;
?>
<tr>
    <td class="col-opt">Cleanup</td>
    <td>
        <b>Remove disabled plugins/themes</b><br/>
        This option simply removes all plugins and themes that were disabled during the backup.
        <br/><br/>
        <b>Remove users without permissions</b><br/>
        This option removes all users without any capabilities/permissions on a standalone website, for multisite case it will keep the super admins
        and remove any other user who does not have any capabilities.
        <br/><br/>
    </td>
</tr>
