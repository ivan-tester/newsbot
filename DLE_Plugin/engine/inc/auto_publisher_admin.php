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
?>
<?php if ($message): ?>
<div class="alert alert-success"><?php echo ap_e($message); ?></div>
<?php endif; ?>

<style>
/* The site's own theme (light or dark skin) already colors links/text
   correctly — Bootstrap's stock .nav-tabs>.active>a rule hardcodes a white
   background + #555 text, which goes invisible (white-on-white) under a
   dark skin that repaints link text white but doesn't know about this
   plugin-injected tab bar. Strip the hardcoded fill and mark the active
   tab with an underline in the surrounding text color instead, so it
   reads correctly under either skin. */
.ap-tabs .nav-tabs > li > a {
  background: transparent;
  color: inherit;
}
.ap-tabs .nav-tabs > li.active > a,
.ap-tabs .nav-tabs > li.active > a:hover,
.ap-tabs .nav-tabs > li.active > a:focus {
  background: transparent;
  color: inherit;
  font-weight: bold;
  border-color: transparent transparent currentColor;
}
</style>

<form method="post">

<div class="ap-tabs">
<ul class="nav nav-tabs" role="tablist">
  <li class="active"><a href="#ap-tab-connection" data-toggle="tab">Подключение</a></li>
  <li><a href="#ap-tab-howto" data-toggle="tab">Как подключить</a></li>
  <li><a href="#ap-tab-publish" data-toggle="tab">Публикация</a></li>
  <li><a href="#ap-tab-image" data-toggle="tab">Картинка</a></li>
</ul>

<div class="tab-content">

  <div class="tab-pane active" id="ap-tab-connection">
    <div class="panel panel-default">
      <div class="panel-body">

        <div class="form-group">
          <label>Эндпоинт</label>
          <div><code><?php echo ap_e($endpoint); ?></code></div>
          <p class="note">Заголовок запроса: <code>X-AutoPublisher-Token: &lt;токен&gt;</code></p>
        </div>

        <div class="form-group">
          <label>Токен доступа</label>
          <?php if ($justRegeneratedToken !== null): ?>
            <div class="input-group">
              <input type="text" class="form-control" readonly style="word-break:break-all;" value="<?php echo ap_e($justRegeneratedToken); ?>">
              <span class="input-group-btn">
                <button type="button" class="btn btn-default" id="ap-copy-token" data-token="<?php echo ap_e($justRegeneratedToken); ?>">Скопировать</button>
              </span>
            </div>
            <p class="note" style="color:#a94442;">Сохраните сейчас — повторно в открытом виде не показывается.</p>
          <?php elseif ($cfg['token'] !== ''): ?>
            <p><code><?php echo ap_e(substr($cfg['token'], 0, 8)); ?>…</code> <span class="note">(скрыт)</span></p>
          <?php else: ?>
            <p><em>не сгенерирован — сохраните форму, чтобы создать</em></p>
          <?php endif; ?>
          <div class="form-group">
            <label>Сгенерировать новый токен (старый перестанет работать)</label><br>
            <input class="switch" type="checkbox" name="regenerate_token" value="1">
          </div>
        </div>

      </div>
    </div>
  </div>

  <div class="tab-pane" id="ap-tab-howto">
    <div class="panel panel-default">
      <div class="panel-body">
        <ol style="margin:0; padding-left:20px;">
          <li>Выберите категорию и укажите логин уже существующего на сайте пользователя как автора публикаций.</li>
          <li>Нажмите «Сохранить» — при первом сохранении сгенерируется токен доступа. Он показывается один раз, сразу после генерации — скопируйте его сейчас.</li>
          <li>В боте-модераторе: настройки публикации → добавить этот сайт как цель → введите адрес сайта → вставьте скопированный токен. Бот сам проверит подключение перед сохранением.</li>
          <li>Готово — материалы, привязанные к этому сайту как цели публикации, начнут сюда приходить.</li>
        </ol>
        <div class="note" style="margin-top:10px; color:#a94442;">
          <b>Отдельный обязательный шаг, без которого приём материалов не заработает:</b> в файле <code>main.tpl</code> активного шаблона сайта должен быть тег, подключающий обработчик плагина при <code>?do=auto_publisher</code> (обычная установка плагина через админку сама его не добавляет):<br>
          <code>[available=auto_publisher]{include file="engine/modules/auto_publisher.php"}[/available]</code>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-pane" id="ap-tab-publish">
    <div class="panel panel-default">
      <div class="panel-body">

        <div class="form-group">
          <label>Разрешить несколько категорий для одной новости</label><br>
          <input class="switch" type="checkbox" name="category_multi" value="1"<?php echo !empty($cfg['category_multi']) ? ' checked' : ''; ?><?php echo !$dleMultiCategoryEnabled ? ' disabled' : ''; ?>>
        </div>
        <?php if (!$dleMultiCategoryEnabled): ?>
          <p class="note" style="color:#a94442;">Мультикатегории выключены в настройках самого сайта (Настройки скрипта → Настройка системы → «Включить поддержку мультикатегорий на сайте») — включите их там, чтобы этот переключатель заработал.</p>
        <?php endif; ?>
        <p class="note">Влияет и на бот: при синхронизации цели («🔄 Синхронизировать с DLE») бот подтягивает этот режим и предлагает несколько категорий вместо одной, если он включён здесь.</p>

        <div class="form-group">
          <label>Категория для публикаций (одна — режим по умолчанию)</label>
          <select name="category" class="uniform" data-width="100%">
            <option value="0">— выберите категорию —</option>
            <?php foreach ($allCategories as $id => $name): ?>
              <option value="<?php echo (int)$id; ?>"<?php echo ((int)$cfg['category'] === (int)$id) ? ' selected' : ''; ?>><?php echo ap_e($name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Категории по умолчанию (если включён режим «несколько категорий»)</label>
          <select data-placeholder="Выберите категории" name="categories[]" class="categoryselect" style="width:100%;max-width:24.3em;" multiple>
            <?php foreach ($allCategories as $id => $name): ?>
              <option value="<?php echo (int)$id; ?>"<?php echo in_array((int)$id, array_map('intval', $cfg['categories']), true) ? ' selected' : ''; ?>><?php echo ap_e($name); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="note">Используется только когда бот не прислал собственный выбор категорий для конкретной новости.</p>
        </div>

        <?php if (!$categoryConfigured): ?>
          <p class="note" style="color:#a94442;">Категория (или категории) пока не выбраны — приём материалов будет отклоняться с ошибкой.</p>
        <?php endif; ?>

        <div class="form-group">
          <label>Автор публикаций</label>
          <input type="text" name="author" class="form-control" value="<?php echo ap_e($cfg['author']); ?>">
          <p class="note">Логин существующего пользователя сайта, от имени которого будут публиковаться материалы.</p>
        </div>

        <div class="form-group">
          <label>Публиковать сразу, без дополнительной модерации на сайте</label><br>
          <input class="switch" type="checkbox" name="auto_approve" value="1"<?php echo !empty($cfg['auto_approve']) ? ' checked' : ''; ?>>
        </div>
        <p class="note">Рекомендуется включить, если материалы уже прошли модерацию во внешнем боте. Если выключить, они будут падать в очередь модерации DLE (approve=0).</p>

      </div>
    </div>
  </div>

  <div class="tab-pane" id="ap-tab-image">
    <div class="panel panel-default">
      <div class="panel-body">

        <div class="form-group">
          <?php foreach ($imageModeLabels as $modeValue => $modeInfo): ?>
            <div class="radio">
              <label>
                <input type="radio" name="image_mode" value="<?php echo ap_e($modeValue); ?>"<?php echo $cfg['image_mode'] === $modeValue ? ' checked' : ''; ?>>
                <?php echo ap_e($modeInfo['title']); ?>
                <span class="note"><?php echo $modeInfo['hint']; ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-group">
          <label>Имя доп. поля</label>
          <input type="text" name="image_xfield_name" class="form-control" value="<?php echo ap_e($cfg['image_xfield_name']); ?>" placeholder="image">
          <p class="note">Поле должно быть создано в настройках категории (тип «Текст» или «Картинка», совпадающий с выбранным выше) — используется только для вариантов [xfield_img]/[xfield_text]. Плагин только заполняет значение существующего поля, не создаёт его.</p>
        </div>

      </div>
    </div>
  </div>

</div>
</div>

<div class="form-group">
  <input type="hidden" name="action" value="save">
  <button class="btn bg-teal btn-sm btn-raised position-left" type="submit"><i class="fa fa-floppy-o position-left"></i>Сохранить</button>
</div>
</form>

<script>
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

if (window.jQuery) {
  // chosen's own library is bundled globally by the admin skin
  // (application.js/application.css) but, unlike select.uniform and
  // .switch, its init call is NOT automatic — DLE's own editnews.php
  // calls this explicitly per-page, so this plugin page has to as well.
  jQuery('.categoryselect').chosen({ allow_single_deselect: true, no_results_text: 'Категория не найдена' });
}

(function () {
  // Plain-JS tab switching so this works even if the current admin skin
  // doesn't wire up Bootstrap's own data-toggle="tab" handler for
  // plugin-injected sections (the core settings page tabs, e.g. Общие/
  // Безопасность/Новости, load their own bootstrap.js — this section
  // can't assume that ran).
  var tabLinks = document.querySelectorAll('.nav-tabs a[data-toggle="tab"]');
  for (var i = 0; i < tabLinks.length; i++) {
    tabLinks[i].addEventListener('click', function (e) {
      e.preventDefault();
      var targetId = this.getAttribute('href');
      var tabs = this.closest('.nav-tabs');
      var panes = tabs.parentNode.querySelectorAll('.tab-pane');
      var links = tabs.querySelectorAll('li');
      for (var j = 0; j < links.length; j++) {
        links[j].classList.remove('active');
      }
      for (var k = 0; k < panes.length; k++) {
        panes[k].classList.remove('active');
      }
      this.parentNode.classList.add('active');
      document.querySelector(targetId).classList.add('active');
    });
  }
})();
</script>
<?php
echofooter();
