<?php
// Auto Publisher — admin settings page.
// Registered into {prefix}_admin_sections by the plugin's mysqlinstall.
if (!defined('DATALIFEENGINE') || !defined('LOGGED_IN')) {
    die('Hacking attempt!');
}

require_once ENGINE_DIR . '/inc/auto_publisher_lib.php';

echoheader('Auto Publisher', 'Настройки приёма материалов от внешнего бота');

$cfg = ap_load_config();
$message = '';
$justRegeneratedToken = null;

// Site-wide switch (Настройки скрипта → Настройка системы → «Включить
// поддержку мультикатегорий на сайте», engine/inc/options.php's own
// allow_multi_category) — our own category_multi below is a *separate*
// config value with no relation to this one unless we check it ourselves.
// If the site-wide switch is off, dle_post.category still technically
// accepts a comma-joined value, but nothing in DLE's own admin UI expects
// it and there's no guarantee every template/module reading that column
// was written to handle more than one id — so this stays a hard
// requirement (silently forced off on save below), not just a warning.
$dleMultiCategoryEnabled = !empty($config['allow_multi_category']);

if (!empty($_POST['action']) && $_POST['action'] === 'save') {
    $cfg['category'] = isset($_POST['category']) ? (int)$_POST['category'] : 0;
    $cfg['category_multi'] = $dleMultiCategoryEnabled && !empty($_POST['category_multi']);
    $postedCategories = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];
    $cfg['categories'] = array_values(array_unique(array_filter(array_map('intval', $postedCategories))));
    $cfg['author'] = isset($_POST['author']) ? trim((string)$_POST['author']) : 'AutoPublisherBot';
    $cfg['auto_approve'] = !empty($_POST['auto_approve']);
    $postedImageMode = isset($_POST['image_mode']) ? (string)$_POST['image_mode'] : 'body';
    $cfg['image_mode'] = in_array($postedImageMode, ap_valid_image_modes(), true) ? $postedImageMode : 'body';
    $cfg['image_xfield_name'] = isset($_POST['image_xfield_name']) ? trim((string)$_POST['image_xfield_name']) : '';
    if ($cfg['image_xfield_name'] === '') {
        $cfg['image_xfield_name'] = 'image';
    }

    if (!empty($_POST['regenerate_token']) || $cfg['token'] === '') {
        $cfg['token'] = ap_generate_token();
        $justRegeneratedToken = $cfg['token'];
        $message = 'Настройки сохранены. Новый токен показан ниже один раз — скопируйте его при добавлении этого сайта в боте как цели публикации.';
    } else {
        $message = 'Настройки сохранены.';
    }

    ap_save_config($cfg);
}

$allCategories = ap_categories_map($cat_info);

$endpoint = (isset($config['http_home_url']) ? rtrim($config['http_home_url'], '/') : '') . '/index.php?do=auto_publisher';
$categoryConfigured = ap_category_configured($cfg);

$imageModeLabels = [
    'body' => ['title' => 'В тело статьи', 'hint' => '&lt;img&gt; в начало short_story/full_story'],
    'xfields_image' => ['title' => '[xfield_img]', 'hint' => 'Формат хранения «путь_относительно_uploads|0|0|ШИРИНАxВЫСОТА|размер_файла»'],
    'xfields_text' => ['title' => '[xfield_text]', 'hint' => 'короткая ссылка'],
];

// Local helpers mirroring the exact row/control markup DLE's own core
// modules use (engine/inc/options.php, googlemap.php, userfields.php, ...)
// — each core module redeclares its own private copies of these rather
// than sharing a global one, so `ap_`-prefixing here follows that same
// per-module-local convention instead of risking a redeclare. See
// .claude/skills/dle-plugin/SKILL.md, "Admin settings page" for the
// verified-against-source pattern this implements.
function ap_show_row($title = '', $description = '', $field = '', $class = '')
{
    $classAttr = $class ? " class=\"{$class}\"" : '';
    echo "<tr{$classAttr}>\n"
        . "  <td class=\"col-xs-6 col-sm-6 col-md-7\"><h6 class=\"media-heading text-semibold\">{$title}</h6><div class=\"text-muted text-size-small hidden-xs\">{$description}</div></td>\n"
        . "  <td class=\"col-xs-6 col-sm-6 col-md-5\">{$field}</td>\n"
        . "</tr>\n";
}

function ap_make_dropdown($options, $name, $selected, $optional = '')
{
    $output = "<select class=\"uniform\" name=\"" . ap_e($name) . "\" {$optional}>\n";
    foreach ($options as $value => $label) {
        $selectedAttr = ((string)$selected === (string)$value) ? ' selected' : '';
        $output .= '<option value="' . ap_e($value) . "\"{$selectedAttr}>" . ap_e($label) . "</option>\n";
    }
    $output .= '</select>';
    return $output;
}

function ap_make_checkbox($name, $selected, $optional = '')
{
    $selectedAttr = $selected ? 'checked' : '';
    return '<input class="switch" type="checkbox" name="' . ap_e($name) . "\" value=\"1\" {$selectedAttr} {$optional}>";
}
?>
<?php if ($message): ?>
<div class="alert alert-success"><?php echo ap_e($message); ?></div>
<?php endif; ?>

<style>
/* Only one of the single-/multi-category rows is ever shown at a time
   (see the category_multi switch handler below) — a plain utility
   class instead of inline style so the toggle script only has to add
   or remove one class name. */
.ap-hidden-row { display: none; }
</style>

<!-- Toolbar: same navbar/tab structure DLE's own "Настройки скрипта"
     (engine/inc/options.php) uses for its own tab bar — verified against
     that file's real PHP, not just its rendered markup, so it inherits
     the site's theme styling (light/dark) with no CSS of our own. Our
     own ApChangeTab() below mirrors options.php's own ChangeOption() —
     that function is declared locally inside options.php, not a global
     utility, so this plugin declares its own copy the same way. -->
<div class="navbar navbar-default navbar-component navbar-xs systemsettings">
  <ul class="nav navbar-nav visible-xs-block">
    <li class="full-width text-center"><a data-toggle="collapse" data-target="#ap-navbar-filter"><i class="fa fa-bars"></i></a></li>
  </ul>
  <div class="navbar-collapse collapse" id="ap-navbar-filter">
    <ul class="nav navbar-nav">
      <li class="active"><a onclick="ApChangeTab(this, 'ap-tab-connection');" class="tip" title="Токен доступа и адрес приёма материалов"><i class="fa fa-plug"></i> Подключение</a></li>
      <li><a onclick="ApChangeTab(this, 'ap-tab-howto');" class="tip" title="Пошаговая инструкция"><i class="fa fa-question-circle"></i> Как подключить</a></li>
      <li><a onclick="ApChangeTab(this, 'ap-tab-publish');" class="tip" title="Категории, автор, модерация"><i class="fa fa-newspaper-o"></i> Публикация</a></li>
      <li><a onclick="ApChangeTab(this, 'ap-tab-image');" class="tip" title="Способ вставки обложки"><i class="fa fa-picture-o"></i> Картинка</a></li>
    </ul>
  </div>
</div>
<!-- /toolbar -->

<form action="" method="post" class="systemsettings">

<div id="ap-tab-connection" class="panel panel-flat">
  <table class="table table-striped">
<?php
ap_show_row(
    'Эндпоинт',
    'Заголовок запроса: <code>X-AutoPublisher-Token: &lt;токен&gt;</code>',
    '<code>' . ap_e($endpoint) . '</code>'
);

if ($justRegeneratedToken !== null) {
    $tokenField = '<div class="input-group">'
        . '<input type="text" class="form-control" readonly style="word-break:break-all;" value="' . ap_e($justRegeneratedToken) . '">'
        . '<span class="input-group-btn"><button type="button" class="btn bg-slate-600 btn-raised" id="ap-copy-token" data-token="' . ap_e($justRegeneratedToken) . '">Скопировать</button></span>'
        . '</div>';
    $tokenDescription = '<span style="color:#a94442;">Сохраните сейчас — повторно в открытом виде не показывается.</span>';
} elseif ($cfg['token'] !== '') {
    $tokenField = '<code>' . ap_e(substr($cfg['token'], 0, 8)) . '…</code> <span class="text-muted">(скрыт)</span>';
    $tokenDescription = 'Используется в заголовке запроса выше — вставьте его при добавлении этого сайта в боте как цели публикации.';
} else {
    $tokenField = '<em>не сгенерирован — сохраните форму, чтобы создать</em>';
    $tokenDescription = 'Сгенерируется автоматически при первом сохранении.';
}
$tokenField .= '<br><br><button type="button" class="btn bg-slate-600 btn-sm btn-raised position-left" id="ap-regen-token-btn"><i class="fa fa-refresh position-left"></i>Сгенерировать новый токен</button>';
ap_show_row('Токен доступа', $tokenDescription, $tokenField);
?>
  </table>
</div>

<div id="ap-tab-howto" class="panel panel-flat" style="display:none">
  <div class="panel-body">
    <ol style="margin:0; padding-left:20px;">
      <li>Выберите категорию и укажите логин уже существующего на сайте пользователя как автора публикаций.</li>
      <li>Нажмите «Сохранить» — при первом сохранении сгенерируется токен доступа. Он показывается один раз, сразу после генерации — скопируйте его сейчас.</li>
      <li>В боте-модераторе: настройки публикации → добавить этот сайт как цель → введите адрес сайта → вставьте скопированный токен. Бот сам проверит подключение перед сохранением.</li>
      <li>Готово — материалы, привязанные к этому сайту как цели публикации, начнут сюда приходить.</li>
    </ol>
    <div class="text-muted text-size-small" style="margin-top:10px; color:#a94442;">
      <b>Отдельный обязательный шаг, без которого приём материалов не заработает:</b> в файле <code>main.tpl</code> активного шаблона сайта должен быть тег, подключающий обработчик плагина при <code>?do=auto_publisher</code> (обычная установка плагина через админку сама его не добавляет):<br>
      <code>[available=auto_publisher]{include file="engine/modules/auto_publisher.php"}[/available]</code>
    </div>
  </div>
</div>

<div id="ap-tab-publish" class="panel panel-flat" style="display:none">
  <table class="table table-striped">
<?php
$multiDescription = 'Влияет и на бот: при синхронизации цели («🔄 Синхронизировать с DLE») бот подтягивает этот режим и предлагает несколько категорий вместо одной, если он включён здесь.';
if (!$dleMultiCategoryEnabled) {
    $multiDescription .= '<br><span style="color:#a94442;">Мультикатегории выключены в настройках самого сайта (Настройки скрипта → Настройка системы → «Включить поддержку мультикатегорий на сайте») — включите их там, чтобы этот переключатель заработал.</span>';
}
ap_show_row(
    'Разрешить несколько категорий для одной новости',
    $multiDescription,
    ap_make_checkbox('category_multi', !empty($cfg['category_multi']), $dleMultiCategoryEnabled ? '' : 'disabled')
);

$singleCategoryOptions = ['0' => '— выберите категорию —'];
foreach ($allCategories as $id => $name) {
    $singleCategoryOptions[$id] = $name;
}
ap_show_row(
    'Категория по умолчанию',
    '',
    ap_make_dropdown($singleCategoryOptions, 'category', $cfg['category'], 'data-width="100%"'),
    !empty($cfg['category_multi']) ? 'ap-hidden-row' : ''
);

$categorySelectField = '<select data-placeholder="Выберите категории" name="categories[]" class="categoryselect" style="width:100%;max-width:24.3em;" multiple>';
foreach ($allCategories as $id => $name) {
    $selectedAttr = in_array((int)$id, array_map('intval', $cfg['categories']), true) ? ' selected' : '';
    $categorySelectField .= '<option value="' . (int)$id . '"' . $selectedAttr . '>' . ap_e($name) . '</option>';
}
$categorySelectField .= '</select>';
ap_show_row(
    'Категории по умолчанию',
    'Используется только когда бот не прислал собственный выбор категорий для конкретной новости.',
    $categorySelectField,
    empty($cfg['category_multi']) ? 'ap-hidden-row' : ''
);

if (!$categoryConfigured) {
    ap_show_row('', '<span style="color:#a94442;">Категория (или категории) пока не выбраны — приём материалов будет отклоняться с ошибкой.</span>');
}

ap_show_row(
    'Автор публикаций',
    'Логин существующего пользователя сайта, от имени которого будут публиковаться материалы.',
    '<input type="text" name="author" class="form-control" value="' . ap_e($cfg['author']) . '">'
);

ap_show_row(
    'Публиковать сразу, без дополнительной модерации на сайте',
    'Рекомендуется включить, если материалы уже прошли модерацию во внешнем боте. Если выключить, они будут падать в очередь модерации DLE (approve=0).',
    ap_make_checkbox('auto_approve', !empty($cfg['auto_approve']))
);
?>
  </table>
</div>

<div id="ap-tab-image" class="panel panel-flat" style="display:none">
  <table class="table table-striped">
<?php
$imageModeField = '';
foreach ($imageModeLabels as $modeValue => $modeInfo) {
    $checkedAttr = ($cfg['image_mode'] === $modeValue) ? ' checked' : '';
    $imageModeField .= '<div class="radio"><label>'
        . '<input type="radio" name="image_mode" value="' . ap_e($modeValue) . '"' . $checkedAttr . '> '
        . ap_e($modeInfo['title'])
        . ' <span class="text-muted text-size-small">' . $modeInfo['hint'] . '</span>'
        . '</label></div>';
}
ap_show_row('Способ вставки картинки', '', $imageModeField);
ap_show_row(
    'Имя доп. поля',
    'Поле должно быть создано в настройках категории (тип «Текст» или «Картинка», совпадающий с выбранным выше) — используется только для вариантов [xfield_img]/[xfield_text]. Плагин только заполняет значение существующего поля, не создаёт его.',
    '<input type="text" name="image_xfield_name" class="form-control" value="' . ap_e($cfg['image_xfield_name']) . '" placeholder="image">'
);
?>
  </table>
</div>

<div class="form-group" style="margin-top:15px;">
  <input type="hidden" name="action" value="save">
  <button class="btn bg-teal btn-sm btn-raised position-left" type="submit"><i class="fa fa-floppy-o position-left"></i>Сохранить</button>
</div>
</form>

<script>
function ApChangeTab(obj, selectedTab) {
  var ids = ['ap-tab-connection', 'ap-tab-howto', 'ap-tab-publish', 'ap-tab-image'];
  var items = obj.closest('ul').querySelectorAll('li');
  for (var i = 0; i < items.length; i++) {
    items[i].classList.remove('active');
  }
  obj.parentNode.classList.add('active');
  for (var j = 0; j < ids.length; j++) {
    var pane = document.getElementById(ids[j]);
    if (pane) pane.style.display = (ids[j] === selectedTab) ? '' : 'none';
  }
  return false;
}

(function () {
  var copyBtn = document.getElementById('ap-copy-token');
  if (!copyBtn) return;
  copyBtn.addEventListener('click', function () {
    var token = copyBtn.getAttribute('data-token') || '';
    var done = function () {
      var original = copyBtn.textContent;
      copyBtn.textContent = 'Скопировано';
      setTimeout(function () { copyBtn.textContent = original; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(token).then(done, done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = token;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      done();
    }
  });
})();

document.addEventListener('DOMContentLoaded', function () {
  // Deferred vs. non-deferred load order matters here: this whole
  // <script> block is a plain inline script, so it runs immediately as
  // the parser reaches it — *before* application.js, which has
  // `defer` and therefore only runs once the document is fully parsed
  // (i.e. right before DOMContentLoaded). Calling jQuery('.x').chosen()
  // at parse time would hit `chosen` before application.js has even
  // defined it. Waiting for DOMContentLoaded guarantees application.js
  // (and the jQuery/chosen/Switchery libraries it bundles) already ran.
  if (window.jQuery) {
    // chosen's own library is bundled globally by the admin skin
    // (application.js/application.css) but, unlike select.uniform and
    // .switch, its init call is NOT automatic — DLE's own editnews.php
    // calls this explicitly per-page, so this plugin page has to as well.
    jQuery('.categoryselect').chosen({ allow_single_deselect: true, no_results_text: 'Категория не найдена' });
  }

  // Only one of the two category controls is ever relevant at a time —
  // toggle which row shows as the switch is flipped, instead of both
  // sitting on screen together. Switchery dispatches a real 'change'
  // event on the underlying checkbox when clicked (see its own
  // handleOnchange in application.js), so a plain listener works.
  var categoryMultiSwitch = document.querySelector('input[name="category_multi"]');
  var singleRow = document.querySelector('select[name="category"]').closest('tr');
  var multiRow = document.querySelector('.categoryselect').closest('tr');
  if (categoryMultiSwitch && singleRow && multiRow) {
    categoryMultiSwitch.addEventListener('change', function () {
      var isMulti = categoryMultiSwitch.checked;
      singleRow.classList.toggle('ap-hidden-row', isMulti);
      multiRow.classList.toggle('ap-hidden-row', !isMulti);
    });
  }

  // Token regeneration used to be a checkbox you had to tick and then
  // separately hit "Сохранить" — easy to trigger by accident. Now it's
  // its own button: confirm, then submit the form with a one-off
  // hidden field so the existing save handler (which already knows how
  // to regenerate + reveal a token, see $justRegeneratedToken above)
  // does the rest, same as it always has.
  var regenBtn = document.getElementById('ap-regen-token-btn');
  if (regenBtn) {
    regenBtn.addEventListener('click', function () {
      if (!window.confirm('Старый токен перестанет работать, все сайты, использующие его, отключатся от бота. Сгенерировать новый токен?')) {
        return;
      }
      var form = regenBtn.closest('form');
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'regenerate_token';
      hidden.value = '1';
      form.appendChild(hidden);
      form.submit();
    });
  }
});
</script>
<?php
echofooter();
