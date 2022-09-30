<?php
$beUser = rex::getUser();

if($beUser->hasPerm("collaborate[]")):

$collaborate = rex_addon::get("collaborate");
?>
<div class="collaborate header-box <?=
    ($beUser->hasPerm("collaborate[users]") ? 'expandable show-users' : '');
    ?>" data-status="CONNECTING" data-start-time="" <?=
    ($beUser->hasPerm("collaborate[users]") ? 'data-users="0"' : '');
    ?>>
    <div class="status btn-group-xs">
        <figure class="indicator"></figure>

        <div>
            <div><?= $collaborate->i18n("header_status"); ?></div>

            <div class="sub-status">
                <span
                    data-status-online="<?= $collaborate->i18n("header_status_ONLINE"); ?>"
                    data-status-offline="<?= $collaborate->i18n("header_status_OFFLINE"); ?>"
                    data-status-closed="<?= $collaborate->i18n("header_status_CLOSED"); ?>"
                    data-status-connecting="<?= $collaborate->i18n("header_status_CONNECTING"); ?>">
                </span>
                <span class="since">
                    <span><?= $collaborate->i18n("since"); ?></span>
                    <span class="counter"></span>
                </span>
            </div>

            <div class="user-count">
                <span class="value"></span>
                <span class="label"><?= $collaborate->i18n("header_user_count"); ?></span>
            </div>
        </div>

        <button class="btn btn-primary refresh" type="button" data-toggle="tooltip" data-placement="right" title="<?= $collaborate->i18n("header_refresh"); ?>">
            <i class="fa fa-refresh" aria-hidden="true"></i>
        </button>
    </div>

    <?php
    // check if right for expandable box exists
    if($beUser->hasPerm("collaborate[users]")):
    ?>
    <div class="user-info">
        <ul></ul>
    </div>
    <?php endif; ?>
</div>

<?php
endif;