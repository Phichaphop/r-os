<?php
/**
 * UI Showcase (P0-05 §8) — every token, component state and page pattern with DEMO data.
 * Built in phase 1 before any business module. Disabled in production.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 */
$colors = ['bg', 'surface', 'surface-2', 'surface-sunken', 'text', 'text-muted', 'action-primary', 'on-primary', 'primary-soft', 'navy', 'navy-accent',
    'success', 'success-subtle', 'warning', 'warning-subtle', 'danger', 'danger-subtle', 'info', 'info-subtle', 'border', 'border-input', 'focus-ring'];
$icons = ['home', 'list-check', 'users', 'calendar-event', 'clock', 'currency-baht', 'receipt', 'building', 'building-store', 'shopping-cart', 'settings', 'bell',
    'search', 'filter', 'download', 'upload', 'plus', 'pencil', 'trash', 'copy', 'eye', 'eye-off', 'lock', 'lock-open', 'alert-triangle', 'circle-check',
    'info-circle', 'circle-x', 'calculator', 'qrcode', 'gift', 'typography', 'external-link', 'file-type-pdf', 'tool', 'wallet', 'report-money', 'map-pin',
    'id-badge-2', 'cloud-off', 'history', 'shield-check', 'robot-off', 'palette', 'database', 'inbox', 'send', 'cash', 'key', 'language', 'sun', 'moon'];
$sections = [
    'tokens' => $t('showcase.sec.tokens'), 'icons' => $t('showcase.sec.icons'), 'buttons' => $t('showcase.sec.buttons'),
    'forms' => $t('showcase.sec.forms'), 'status' => $t('showcase.sec.status'), 'feedback' => $t('showcase.sec.feedback'),
    'data' => $t('showcase.sec.data'), 'layout' => $t('showcase.sec.layout'), 'overlays' => $t('showcase.sec.overlays'),
    'states' => $t('showcase.sec.states'), 'blue' => $t('showcase.sec.blue'), 'patterns' => $t('showcase.sec.patterns'), 'pending' => $t('showcase.sec.pending'),
];
$statuses = ['draft', 'submitted', 'pending', 'returned', 'approved', 'rejected', 'cancelled', 'locked', 'posted', 'reversed', 'paid', 'failed', 'pending_sync', 'sync_failed', 'processing'];
$rows = [
    ['LV-2569-0012', 'EMP-0007', 'สมชาย ใจดี', 'ลาพักร้อน', '14 ต.ค. 2569', '1.0', 'pending'],
    ['LV-2569-0011', 'EMP-0023', 'วิภา รักงาน', 'ลาป่วย', '12 ต.ค. 2569', '0.5', 'approved'],
    ['LV-2569-0010', 'EMP-0031', 'Anan K.', 'ลากิจ', '10 ต.ค. 2569', '1.0', 'returned'],
    ['LV-2569-0009', 'EMP-0007', 'สมชาย ใจดี', 'ลาพักร้อน', '2 ต.ค. 2569', '2.0', 'rejected'],
];
$btn = static fn (array $p): string => $ui('button', $p);
?>
<?= $ui('page-header', [
    'title' => $t('showcase.title'),
    'subtitle' => $t('showcase.subtitle'),
    'crumbs' => [[$t('nav.home'), $url('/')], [$t('nav.group_system'), null], [$t('showcase.title'), null]],
    'actions' => '<fieldset class="segmented" aria-label="' . $e($t('pref.density')) . '">'
        . '<input type="radio" id="d-comf" name="density" value="comfortable" data-action="set-density"><label for="d-comf">' . $e($t('pref.density_comfortable')) . '</label>'
        . '<input type="radio" id="d-comp" name="density" value="compact" data-action="set-density"><label for="d-comp">' . $e($t('pref.density_compact')) . '</label></fieldset>'
        . $btn(['label' => $t('pref.theme_toggle'), 'icon' => 'moon', 'attrs' => ['data-action' => 'toggle-theme']]),
]) ?>

<nav class="sc-nav" aria-label="<?= $e($t('showcase.toc')) ?>">
  <?php foreach ($sections as $id => $label): ?><a class="chip" href="#sc-<?= $e($id) ?>"><?= $e($label) ?></a><?php endforeach; ?>
</nav>

<!-- 1. TOKENS -->
<section id="sc-tokens" class="sc-section card" aria-labelledby="h-tokens">
  <h2 id="h-tokens"><?= $ui('icon', ['name' => 'palette']) ?><?= $e($sections['tokens']) ?></h2>
  <h3><?= $e($t('showcase.colors')) ?></h3>
  <div class="sc-grid">
    <?php foreach ($colors as $c): ?>
      <div class="sc-token"><div class="swatch sw-<?= $e($c) ?>"></div><code>--color-<?= $e($c) ?></code></div>
    <?php endforeach; ?>
  </div>
  <h3><?= $e($t('showcase.type')) ?></h3>
  <div>
    <?php foreach (['xs' => '12', 'sm' => '13', 'md' => '14', 'base' => '16', 'lg' => '18', 'xl' => '22', '2xl' => '28'] as $k => $px): ?>
      <div class="sc-type-sample"><code>--font-size-<?= $e($k) ?> · <?= $e($px) ?>px</code><span class="sc-fs-<?= $e($k) ?>">ระบบบริหารทรัพยากร Blue OS · ลาพักร้อน 6.0 วัน · ฿12,450.00</span></div>
    <?php endforeach; ?>
    <div class="sc-type-sample"><code>--font-display</code><span class="sc-display sc-fs-xl">Bai Jamjuree · หัวข้อ 1,245,000.00</span></div>
    <div class="sc-type-sample"><code>--font-mono</code><span class="sc-mono">LV-2569-0012 · 01JB7Q3Z9K2M4N6P8R0S2T4V6W</span></div>
  </div>
  <div class="grid grid--3">
    <div class="stack-sm"><h3><?= $e($t('showcase.spacing')) ?></h3>
      <?php foreach ([1, 2, 3, 4, 6, 8, 12] as $s): ?><div class="sc-space"><span class="sc-space__bar sc-w-<?= $s ?>"></span>--space-<?= $s ?></div><?php endforeach; ?></div>
    <div class="stack-sm"><h3><?= $e($t('showcase.radius')) ?></h3>
      <div class="row"><?php foreach (['sm', 'md', 'lg'] as $r): ?><div class="sc-token"><div class="sc-radius sc-r-<?= $r ?>"></div><code>--radius-<?= $r ?></code></div><?php endforeach; ?></div></div>
    <div class="stack-sm"><h3><?= $e($t('showcase.elevation')) ?></h3>
      <div class="row"><div class="card sc-shadow-1">--shadow-1</div><div class="card sc-shadow-2">--shadow-2</div></div></div>
  </div>
</section>

<!-- 2. ICONS -->
<section id="sc-icons" class="sc-section card" aria-labelledby="h-icons">
  <h2 id="h-icons"><?= $ui('icon', ['name' => 'components']) ?><?= $e($sections['icons']) ?></h2>
  <p class="text-muted"><?= $e($t('showcase.icons_note')) ?></p>
  <ul role="list" class="sc-icons">
    <?php foreach ($icons as $i): ?><li><?= $ui('icon', ['name' => $i, 'size' => 'lg']) ?><?= $e($i) ?></li><?php endforeach; ?>
    <li><img class="sc-brand-logo" src="<?= $e($asset('ui/icons/logo-blue.png')) ?>" alt="" width="70" height="32">logo-blue (Main_logo_blue)</li>
    <li><img class="sc-brand-logo" src="<?= $e($asset('ui/icons/logo-blue-os.png')) ?>" alt="" width="101" height="32">logo-blue-os (Main_logo_blue_os)</li>
    <li><img src="<?= $e($asset('ui/icons/mark-blue.png')) ?>" alt="" width="32" height="32">mark-blue (favicon)</li>
  </ul>
</section>

<!-- 3. BUTTONS -->
<section id="sc-buttons" class="sc-section card stack" aria-labelledby="h-buttons">
  <h2 id="h-buttons"><?= $ui('icon', ['name' => 'plus']) ?><?= $e($sections['buttons']) ?></h2>
  <div class="row">
    <?= $btn(['label' => 'ส่งคำขอ', 'variant' => 'primary', 'icon' => 'send']) ?>
    <?= $btn(['label' => 'ส่งออก', 'variant' => 'secondary', 'icon' => 'download']) ?>
    <?= $btn(['label' => 'ดูรายละเอียด', 'variant' => 'ghost']) ?>
    <?= $btn(['label' => 'ยืนยันลบ', 'variant' => 'danger', 'icon' => 'trash']) ?>
    <?= $btn(['label' => 'ตัวกรอง', 'icon' => 'filter', 'iconOnly' => true]) ?>
  </div>
  <div class="row">
    <?= $btn(['label' => 'เล็ก (sm)', 'variant' => 'primary', 'size' => 'sm']) ?>
    <?= $btn(['label' => 'ปกติ (md)', 'variant' => 'primary']) ?>
    <?= $btn(['label' => 'ใหญ่ (lg) — POS', 'variant' => 'primary', 'size' => 'lg']) ?>
  </div>
  <div class="row">
    <?= $btn(['label' => 'อนุมัติ 12 รายการ', 'variant' => 'primary', 'disabled' => true, 'disabledReason' => 'ต้องเลือกรายการก่อน']) ?>
    <?= $btn(['label' => 'กำลังบันทึก…', 'variant' => 'primary', 'loading' => true]) ?>
    <?= $btn(['label' => 'คลิกเพื่อดูสถานะ loading', 'attrs' => ['data-demo-loading' => '1']]) ?>
    <?= $btn(['label' => 'แสดง toast', 'icon' => 'bell', 'attrs' => ['data-toast' => 'บันทึกร่างแล้ว']]) ?>
  </div>
  <div class="row">
    <div class="sc-mininav navy-dots on-navy row"><?= $btn(['label' => 'บนพื้นกรมท่า', 'variant' => 'on-navy', 'icon' => 'settings']) ?></div>
  </div>
</section>

<!-- 4. FORMS -->
<section id="sc-forms" class="sc-section card stack" aria-labelledby="h-forms">
  <h2 id="h-forms"><?= $ui('icon', ['name' => 'pencil']) ?><?= $e($sections['forms']) ?></h2>
  <form class="form-grid" action="#" novalidate>
    <?= $ui('field', ['name' => 'emp_name', 'label' => 'ชื่อ-นามสกุล', 'required' => true, 'value' => 'สมชาย ใจดี']) ?>
    <?= $ui('field', ['name' => 'nickname', 'label' => 'ชื่อเล่น', 'help' => 'ใช้ค้นหาในทำเนียบ']) ?>
    <?= $ui('field', ['name' => 'leave_type', 'label' => 'ประเภทการลา', 'type' => 'select', 'required' => true, 'value' => 'annual', 'options' => ['annual' => 'ลาพักร้อน', 'sick' => 'ลาป่วย', 'personal' => 'ลากิจ']]) ?>
    <?= $ui('field', ['name' => 'leave_date', 'label' => 'วันที่ลา', 'type' => 'date', 'required' => true, 'value' => '2026-10-14', 'help' => 'แสดงผลเป็น พ.ศ. ในตาราง (UX-04)']) ?>
    <?= $ui('field', ['name' => 'amount', 'label' => 'จำนวนเงิน', 'type' => 'money', 'required' => true, 'value' => '12,450.00', 'prefix' => 'THB']) ?>
    <?= $ui('field', ['name' => 'days', 'label' => 'จำนวนวัน', 'required' => true, 'value' => '7.5', 'error' => 'วันลาพักร้อนคงเหลือไม่พอ (เหลือ 6.0 วัน) — เลือกประเภทลาอื่นหรือลดจำนวนวัน']) ?>
    <?= $ui('field', ['name' => 'emp_code', 'label' => 'รหัสพนักงาน', 'value' => 'EMP-0007', 'readonly' => true, 'help' => 'อ่านอย่างเดียว']) ?>
    <?= $ui('field', ['name' => 'locked_field', 'label' => 'งวดเงินเดือน', 'value' => 'ต.ค. 2569', 'disabled' => true]) ?>
    <?= $ui('field', ['name' => 'reason', 'label' => 'เหตุผล', 'type' => 'textarea', 'full' => true, 'help' => 'ไม่เกิน 500 ตัวอักษร']) ?>
    <div class="field field--full">
      <span class="field__label" id="half-l">ช่วงเวลา <span class="field__req"><?= $e($t('form.required_tag')) ?></span></span>
      <div class="segmented" role="radiogroup" aria-labelledby="half-l">
        <input type="radio" id="h-full" name="half" value="full" checked><label for="h-full">เต็มวัน</label>
        <input type="radio" id="h-am" name="half" value="am"><label for="h-am">ครึ่งเช้า</label>
        <input type="radio" id="h-pm" name="half" value="pm"><label for="h-pm">ครึ่งบ่าย</label>
      </div>
    </div>
    <div class="field">
      <span class="field__label">ตัวเลือก</span>
      <label class="check"><input type="checkbox" checked> แจ้งหัวหน้าทางอีเมล</label>
      <label class="check"><input type="checkbox"> แนบใบรับรองแพทย์ภายหลัง</label>
    </div>
    <fieldset class="field">
      <legend class="field__label">ช่องทางรับสลิป</legend>
      <label class="check"><input type="radio" name="slip" checked> ในแอป</label>
      <label class="check"><input type="radio" name="slip"> อีเมล</label>
    </fieldset>
    <div class="field"><label class="switch"><input type="checkbox" role="switch" checked> เปิดการแจ้งเตือน</label></div>
  </form>
</section>

<!-- 5. STATUS -->
<section id="sc-status" class="sc-section card stack-sm" aria-labelledby="h-status">
  <h2 id="h-status"><?= $ui('icon', ['name' => 'circle-check']) ?><?= $e($sections['status']) ?></h2>
  <p class="text-muted"><?= $e($t('showcase.status_note')) ?></p>
  <div class="row"><?php foreach ($statuses as $s): ?><?= $ui('badge', ['status' => $s]) ?><?php endforeach; ?></div>
</section>

<!-- 6. FEEDBACK -->
<section id="sc-feedback" class="sc-section card stack" aria-labelledby="h-feedback">
  <h2 id="h-feedback"><?= $ui('icon', ['name' => 'bell']) ?><?= $e($sections['feedback']) ?></h2>
  <?= $ui('alert', ['tone' => 'info', 'title' => 'ข้อมูล', 'text' => 'ระบบจะดึงข้อมูลเวลาจากเครื่องสแกนทุก 4 ชั่วโมง']) ?>
  <?= $ui('alert', ['tone' => 'success', 'title' => 'สำเร็จ', 'text' => 'นำเข้า 245 แถว ผ่านทั้งหมด']) ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => 'ควรตรวจสอบ', 'text' => 'มี 3 รายการลงเวลานอกพื้นที่ — รอหัวหน้าตรวจ']) ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => 'บันทึกไม่สำเร็จ', 'text' => 'วันลาพักร้อนคงเหลือไม่พอ (เหลือ 1.0 วัน) — เลือกประเภทลาอื่นหรือลดจำนวนวัน']) ?>
  <div class="row"><?= $btn(['label' => 'แสดง Toast (aria-live)', 'icon' => 'bell', 'attrs' => ['data-toast' => 'อนุมัติคำขอลาแล้ว 1 รายการ']]) ?>
    <span class="tooltip"><?= $btn(['label' => 'คำอธิบาย', 'icon' => 'help-circle', 'iconOnly' => true, 'variant' => 'ghost', 'attrs' => ['aria-describedby' => 'tt-1']]) ?><span class="tooltip__bubble" role="tooltip" id="tt-1">Tooltip ใช้กับคำอธิบายสั้น</span></span></div>
</section>

<!-- 7. DATA DISPLAY -->
<section id="sc-data" class="sc-section card stack" aria-labelledby="h-data">
  <h2 id="h-data"><?= $ui('icon', ['name' => 'list-check']) ?><?= $e($sections['data']) ?></h2>
  <div class="filter-bar">
    <?= $ui('field', ['name' => 'q', 'label' => 'ค้นหา', 'placeholder' => 'รหัส ชื่อ หรือชื่อเล่น']) ?>
    <?= $ui('field', ['name' => 'f_status', 'label' => 'สถานะ', 'type' => 'select', 'options' => ['' => 'ทั้งหมด', 'pending' => 'รออนุมัติ']]) ?>
    <?= $btn(['label' => 'ตัวกรองเพิ่มเติม', 'icon' => 'filter']) ?>
  </div>
  <div class="row"><span class="chip">สถานะ: รออนุมัติ <button type="button" aria-label="ลบตัวกรอง สถานะ"><?= $ui('icon', ['name' => 'x', 'size' => 'sm']) ?></button></span>
    <span class="chip">เดือน: ต.ค. 2569 <button type="button" aria-label="ลบตัวกรอง เดือน"><?= $ui('icon', ['name' => 'x', 'size' => 'sm']) ?></button></span></div>
  <div class="bulk-bar" role="region" aria-label="การกระทำกับรายการที่เลือก"><strong>เลือก 1 รายการ</strong>
    <?= $btn(['label' => 'อนุมัติ', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'circle-check']) ?><?= $btn(['label' => 'ส่งออก', 'size' => 'sm']) ?><?= $btn(['label' => 'ยกเลิกการเลือก', 'variant' => 'ghost', 'size' => 'sm']) ?></div>
  <div class="table-wrap" role="region" aria-label="ตารางคำขอลา (ตัวอย่าง)" tabindex="0">
    <table class="table table--stack">
      <thead><tr>
        <th scope="col"><span class="sr-only">เลือก</span></th>
        <th scope="col" aria-sort="descending"><button class="table__sort" type="button">เลขที่ <?= $ui('icon', ['name' => 'sort-descending', 'size' => 'sm']) ?></button></th>
        <th scope="col">พนักงาน</th><th scope="col">ประเภท</th><th scope="col">วันที่</th><th scope="col" class="col-num">วัน</th><th scope="col">สถานะ</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr<?= $i === 0 ? ' aria-selected="true"' : '' ?>>
          <td data-label="เลือก"><label class="check"><input type="checkbox"<?= $i === 0 ? ' checked' : '' ?> aria-label="เลือก <?= $e($r[0]) ?>"></label></td>
          <td data-label="เลขที่" class="mono"><?= $e($r[0]) ?></td>
          <td data-label="พนักงาน"><span class="mono text-muted"><?= $e($r[1]) ?></span> <?= $e($r[2]) ?></td>
          <td data-label="ประเภท"><?= $e($r[3]) ?></td><td data-label="วันที่"><?= $e($r[4]) ?></td>
          <td data-label="วัน" class="col-num"><?= $e($r[5]) ?></td><td data-label="สถานะ"><?= $ui('badge', ['status' => $r[6]]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5">รวม</td><td class="col-num" data-label="รวมวัน">4.5</td><td></td></tr></tfoot>
    </table>
  </div>
  <?= $ui('pagination', ['path' => '/system/ui-showcase', 'query' => [], 'page' => 3, 'per' => 20, 'total' => 1245]) ?>
</section>

<!-- 8. LAYOUT -->
<section id="sc-layout" class="sc-section card stack" aria-labelledby="h-layout">
  <h2 id="h-layout"><?= $ui('icon', ['name' => 'layout-dashboard']) ?><?= $e($sections['layout']) ?></h2>
  <div class="tabs">
    <div class="tabs__list" role="tablist" aria-label="ข้อมูลพนักงาน">
      <button class="tabs__tab" role="tab" id="tab-1" aria-selected="true" aria-controls="tp-1" type="button">ข้อมูลทั่วไป</button>
      <button class="tabs__tab" role="tab" id="tab-2" aria-selected="false" aria-controls="tp-2" tabindex="-1" type="button">ประวัติการลา</button>
      <button class="tabs__tab" role="tab" id="tab-3" aria-selected="false" aria-controls="tp-3" tabindex="-1" type="button">เอกสาร</button>
    </div>
    <div class="tabs__panel" role="tabpanel" id="tp-1" aria-labelledby="tab-1" tabindex="0">แท็บใช้ลูกศรซ้าย/ขวา, Home, End ได้</div>
    <div class="tabs__panel" role="tabpanel" id="tp-2" aria-labelledby="tab-2" tabindex="0" hidden>ประวัติการลา (ตัวอย่าง)</div>
    <div class="tabs__panel" role="tabpanel" id="tp-3" aria-labelledby="tab-3" tabindex="0" hidden>เอกสาร (ตัวอย่าง)</div>
  </div>
  <div class="grid grid--4">
    <div class="card"><div class="stat__label">วันลาพักร้อนคงเหลือ</div><div class="stat__value">6.0 <span class="text-sm">วัน</span></div><div class="stat__meta"><?= $ui('icon', ['name' => 'calendar-event', 'size' => 'sm']) ?>ถึง 31 ธ.ค. 2569</div></div>
    <div class="card"><div class="stat__label">งานรออนุมัติ</div><div class="stat__value">3</div><div class="stat__meta">เก่าสุด 2 วัน</div></div>
    <div class="card card--navy navy-dots on-navy"><div class="stat__label">ยอดจ่ายสุทธิ ต.ค. 2569</div><div class="stat__value">฿1,245,800.00</div><div class="stat__meta">245 คน · DEMO</div></div>
    <div class="card"><div class="row"><span class="avatar">พบ</span><span class="avatar avatar--lg">สจ</span><div><div>สมชาย ใจดี</div><div class="text-muted text-sm">EMP-0007 · ฝ่ายบุคคล</div></div></div></div>
  </div>
  <div class="menu" data-menu>
    <?= $btn(['label' => 'เพิ่มเติม', 'icon' => 'dots', 'attrs' => ['aria-haspopup' => 'true', 'aria-expanded' => 'false']]) ?>
    <ul class="menu__list" hidden>
      <li><button class="menu__item" type="button"><?= $ui('icon', ['name' => 'copy', 'size' => 'sm']) ?>ทำสำเนา</button></li>
      <li><button class="menu__item" type="button"><?= $ui('icon', ['name' => 'history', 'size' => 'sm']) ?>ดูประวัติ</button></li>
    </ul>
  </div>
</section>

<!-- 9. OVERLAYS + CONFIRMATION LEVELS (P0-05 §3.5) -->
<section id="sc-overlays" class="sc-section card stack" aria-labelledby="h-overlays">
  <h2 id="h-overlays"><?= $ui('icon', ['name' => 'copy']) ?><?= $e($sections['overlays']) ?></h2>
  <div class="row">
    <?= $btn(['label' => 'ระดับ 1: ยืนยันธรรมดา', 'attrs' => ['data-open-dialog' => 'dlg-l1']]) ?>
    <?= $btn(['label' => 'ระดับ 2: สรุปผลกระทบ', 'attrs' => ['data-open-dialog' => 'dlg-l2']]) ?>
    <?= $btn(['label' => 'ระดับ 3: Re-auth', 'icon' => 'key', 'attrs' => ['data-open-dialog' => 'dlg-l3']]) ?>
    <?= $btn(['label' => 'เปิด Drawer', 'icon' => 'layout-sidebar-left-expand', 'attrs' => ['data-open-dialog' => 'dlg-drawer']]) ?>
  </div>
  <dialog class="modal" id="dlg-l1" aria-labelledby="dlg-l1-t">
    <div class="modal__head"><h2 class="modal__title" id="dlg-l1-t">ลบร่างคำขอลา?</h2></div>
    <div class="modal__body">ร่างนี้จะถูกลบและกู้คืนไม่ได้</div>
    <div class="modal__foot"><?= $btn(['label' => 'ยกเลิก', 'attrs' => ['data-close-dialog' => '1']]) ?><?= $btn(['label' => 'ยืนยันลบ', 'variant' => 'danger', 'attrs' => ['data-close-dialog' => '1']]) ?></div>
  </dialog>
  <dialog class="modal" id="dlg-l2" aria-labelledby="dlg-l2-t">
    <div class="modal__head"><h2 class="modal__title" id="dlg-l2-t">อนุมัติรอบเงินเดือน ต.ค. 2569</h2></div>
    <div class="modal__body stack-sm">
      <div class="card"><div class="stat__label">ผลกระทบ (Impact Preview)</div><div>245 คน · ยอดสุทธิ ฿1,245,800.00 · เปลี่ยนจากรอบก่อน +2.1%</div></div>
      <?= $ui('field', ['name' => 'approve_note', 'label' => 'ความเห็น', 'type' => 'textarea']) ?>
    </div>
    <div class="modal__foot"><?= $btn(['label' => 'ยกเลิก', 'attrs' => ['data-close-dialog' => '1']]) ?><?= $btn(['label' => 'อนุมัติ 245 รายการ', 'variant' => 'primary', 'attrs' => ['data-close-dialog' => '1']]) ?></div>
  </dialog>
  <dialog class="modal" id="dlg-l3" aria-labelledby="dlg-l3-t">
    <div class="modal__head"><?= $ui('icon', ['name' => 'shield-check']) ?><h2 class="modal__title" id="dlg-l3-t">ยืนยันตัวตนอีกครั้ง</h2></div>
    <div class="modal__body stack-sm"><p class="text-muted">การสร้างไฟล์โอนเงินเป็นการกระทำเสี่ยงสูง จึงต้องยืนยันรหัสผ่านหรือรหัส TOTP อีกครั้ง</p>
      <?= $ui('field', ['name' => 'reauth', 'label' => 'รหัสผ่าน', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?></div>
    <div class="modal__foot"><?= $btn(['label' => 'ยกเลิก', 'attrs' => ['data-close-dialog' => '1']]) ?><?= $btn(['label' => 'ยืนยันและสร้างไฟล์', 'variant' => 'primary', 'attrs' => ['data-close-dialog' => '1']]) ?></div>
  </dialog>
  <dialog class="modal drawer" id="dlg-drawer" aria-labelledby="dlg-dr-t">
    <div class="modal__head"><h2 class="modal__title" id="dlg-dr-t">LV-2569-0012</h2><?= $btn(['label' => 'ปิด', 'icon' => 'x', 'iconOnly' => true, 'variant' => 'ghost', 'attrs' => ['data-close-dialog' => '1']]) ?></div>
    <div class="modal__body stack-sm"><?= $ui('badge', ['status' => 'pending']) ?><p>สมชาย ใจดี · ลาพักร้อน 1.0 วัน · 14 ต.ค. 2569</p><a href="#sc-overlays">เปิดเต็มหน้า</a></div>
  </dialog>
</section>

<!-- 10. STATES -->
<section id="sc-states" class="sc-section stack" aria-labelledby="h-states">
  <h2 id="h-states"><?= $ui('icon', ['name' => 'inbox']) ?><?= $e($sections['states']) ?></h2>
  <div class="grid grid--2">
    <div class="card"><?= $ui('states', ['title' => 'ยังไม่มีคำขอลา', 'text' => 'คำขอที่คุณยื่นจะแสดงที่นี่ เริ่มได้จากปุ่มยื่นคำขอลา', 'action' => $btn(['label' => 'ยื่นคำขอลา', 'variant' => 'primary', 'icon' => 'plus'])]) ?></div>
    <div class="card"><?= $ui('states', ['kind' => 'error', 'title' => 'โหลดข้อมูลไม่สำเร็จ', 'text' => 'ลองใหม่อีกครั้ง หากยังไม่ได้ให้แจ้งผู้ดูแลพร้อมรหัสอ้างอิง', 'reference' => '01JB7Q3Z9K2M4N6P8R0S2T4V6W', 'action' => $btn(['label' => 'ลองใหม่', 'icon' => 'refresh'])]) ?></div>
    <div class="card"><?= $ui('states', ['kind' => 'forbidden', 'title' => 'ไม่มีสิทธิ์เข้าถึง', 'text' => 'หน้านี้สำหรับ HR Admin หากต้องใช้งานให้ติดต่อผู้ดูแลระบบ']) ?></div>
    <div class="card stack-sm" role="status" aria-busy="true" aria-label="กำลังโหลด"><div class="skeleton skeleton--title"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton skeleton--block"></div></div>
  </div>
  <div class="row"><?= $btn(['label' => 'ดูหน้า error จริง (500 — ไม่มี stack trace)', 'href' => $url('/system/ui-showcase/error-demo'), 'icon' => 'alert-triangle']) ?>
    <?= $btn(['label' => 'ดูหน้า 404', 'href' => $url('/system/ไม่มีหน้านี้'), 'icon' => 'search']) ?></div>
</section>

<!-- 11. Blue OS COMPONENTS (P0-05 §3.2) -->
<section id="sc-blue" class="sc-section card stack" aria-labelledby="h-blue">
  <h2 id="h-blue"><?= $ui('icon', ['name' => 'building']) ?><?= $e($sections['blue']) ?></h2>
  <h3>Context Switcher</h3>
  <div class="row"><button type="button" class="context-switch"><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?><span class="context-switch__entity">บริษัทตัวอย่าง จำกัด (DEMO)</span><span class="context-switch__site">· สำนักงานใหญ่</span><?= $ui('icon', ['name' => 'chevron-down', 'size' => 'sm']) ?></button></div>
  <h3>Masked Value</h3>
  <div class="row masked" data-masked="1-2345-67890-12-3"><span data-masked-value>X-XXXX-XXXXX-12-3</span><?= $btn(['label' => 'แสดง', 'icon' => 'eye', 'size' => 'sm', 'variant' => 'ghost', 'attrs' => ['aria-pressed' => 'false']]) ?><span class="text-muted text-xs">(ระบบจริง: Re-auth + audit ก่อนแสดง · ซ่อนกลับใน 10 วินาที)</span></div>
  <h3>Lock Banner</h3>
  <div class="lock-banner" role="status"><?= $ui('icon', ['name' => 'lock']) ?><span>งวด ต.ค. 2569 ถูกล็อกโดย ผู้อำนวยการฝ่ายบุคคล เมื่อ 31 ต.ค. 2569 18:05</span><span class="spacer"></span><?= $btn(['label' => 'ขอเปิดรอบอีกครั้ง', 'size' => 'sm']) ?></div>
  <h3>Stepper</h3>
  <ol class="stepper" aria-label="ขั้นตอนรอบเงินเดือน">
    <li class="stepper__step stepper__step--done"><span class="stepper__num">1</span>เตรียมข้อมูล<span class="sr-only">(เสร็จแล้ว)</span></li>
    <li class="stepper__step stepper__step--done"><span class="stepper__num">2</span>คำนวณ<span class="sr-only">(เสร็จแล้ว)</span></li>
    <li class="stepper__step stepper__step--current" aria-current="step"><span class="stepper__num">3</span>ตรวจสอบ</li>
    <li class="stepper__step"><span class="stepper__num">4</span>อนุมัติ</li>
    <li class="stepper__step"><span class="stepper__num">5</span>จ่าย/ส่งออก</li>
  </ol>
  <div class="grid grid--2">
    <div><h3>Approval Timeline</h3>
      <ol class="timeline">
        <li class="timeline__item timeline__item--done"><span class="timeline__dot"><?= $ui('icon', ['name' => 'send']) ?></span><div>ยื่นคำขอ — สมชาย ใจดี</div><div class="timeline__meta">12 ต.ค. 2569 09:12</div></li>
        <li class="timeline__item timeline__item--done"><span class="timeline__dot"><?= $ui('icon', ['name' => 'circle-check']) ?></span><div>หัวหน้าแผนกอนุมัติ (ทำแทนโดย วิภา รักงาน)</div><div class="timeline__meta">12 ต.ค. 2569 13:40 · “เรียบร้อย”</div></li>
        <li class="timeline__item timeline__item--current"><span class="timeline__dot"><?= $ui('icon', ['name' => 'hourglass']) ?></span><div>รอ HR Admin</div><div class="timeline__meta">ครบกำหนด 14 ต.ค. 2569</div></li>
      </ol></div>
    <div><h3>Diff Viewer</h3>
      <div class="diff"><div class="diff__col"><div class="diff__head">ค่าเดิม</div><div>อัตรา OT วันหยุด: 3.0 เท่า</div><div>มีผล 1 ม.ค. 2569</div></div>
        <div class="diff__col"><div class="diff__head">ค่าใหม่</div><div class="diff__changed">อัตรา OT วันหยุด: 3.0 เท่า</div><div class="diff__changed">มีผล 1 ม.ค. 2570 <span class="badge badge--info">เปลี่ยน</span></div></div></div>
      <h3>Effective-dated Field</h3>
      <div class="effective"><span>ฐานเงินเดือน: <span class="masked">XX,XXX.00</span></span><span class="text-muted text-sm">มีผลตั้งแต่ 1 ต.ค. 2569 · มีเวอร์ชันตั้งเวลาไว้ 1 ม.ค. 2570 <?= $ui('badge', ['tone' => 'info', 'icon' => 'clock', 'label' => 'ตั้งเวลาไว้']) ?></span></div>
    </div>
  </div>
  <h3>Calculation Trace</h3>
  <details class="trace"><summary>ที่มาของยอด: ค่าล่วงเวลา ฿1,875.00</summary><div class="trace__body">
    <div class="trace__row"><span>กฎ: OT วันทำงาน (config v3)</span><span class="num">1.5 เท่า</span></div>
    <div class="trace__row"><span>ชั่วโมง OT (อนุมัติแล้ว)</span><span class="num">10.00</span></div>
    <div class="trace__row"><span>อัตราต่อชั่วโมง</span><span class="num">125.00</span></div>
    <div class="trace__row"><strong>ผลลัพธ์</strong><strong class="num">1,875.00</strong></div></div></details>
  <h3>Sync Status</h3>
  <div class="row"><span class="sync-status"><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => 'ออนไลน์']) ?></span>
    <span class="sync-status"><?= $ui('badge', ['status' => 'pending_sync']) ?> 3 รายการรอซิงก์</span>
    <span class="sync-status"><?= $ui('badge', ['status' => 'sync_failed']) ?> ลองใหม่อัตโนมัติใน 30 วินาที</span></div>
  <h3>Amount Summary Bar</h3>
  <div class="amount-bar amount-bar--inline navy-dots on-navy" role="status"><div class="amount-bar__item"><span class="amount-bar__label">จำนวนรายการ</span><span class="amount-bar__value">245</span></div>
    <div class="amount-bar__item"><span class="amount-bar__label">ยอดรวม (ต้องตรงกับไฟล์)</span><span class="amount-bar__value">฿1,245,800.00</span></div><span class="spacer"></span>
    <?= $btn(['label' => 'สร้างไฟล์โอนเงิน', 'variant' => 'on-navy', 'icon' => 'download']) ?></div>
</section>

<!-- 12. PAGE PATTERNS -->
<section id="sc-patterns" class="sc-section card stack" aria-labelledby="h-patterns">
  <h2 id="h-patterns"><?= $ui('icon', ['name' => 'layout-dashboard']) ?><?= $e($sections['patterns']) ?></h2>
  <p class="text-muted"><?= $e($t('showcase.patterns_note')) ?></p>
  <div class="grid grid--2">
    <div class="sc-phone" aria-label="ตัวอย่าง Self-service มือถือ (P0-05 §2.3)" role="img">
      <div class="sc-phone__top navy-dots on-navy"><img src="<?= $e($asset('ui/icons/mark-blue.png')) ?>" alt="" width="24" height="24"><strong>Blue OS</strong><span class="spacer"></span><?= $ui('icon', ['name' => 'bell']) ?></div>
      <div class="sc-phone__body">
        <div><strong>สวัสดี พิชาภพ</strong><div class="text-muted text-sm">บริษัทตัวอย่าง · สนญ.</div></div>
        <div class="grid grid--2">
          <span class="sc-tile"><?= $ui('icon', ['name' => 'clock']) ?>ลงเวลา</span><span class="sc-tile"><?= $ui('icon', ['name' => 'calendar-event']) ?>ยื่นลา</span>
          <span class="sc-tile"><?= $ui('icon', ['name' => 'receipt']) ?>สลิป</span><span class="sc-tile"><?= $ui('icon', ['name' => 'id-badge-2']) ?>บัตร</span>
        </div>
        <div class="card"><div class="stat__label">ลาพักร้อนคงเหลือ</div><div class="stat__value">6.0 วัน</div></div>
      </div>
      <div class="sc-bottom"><span aria-current="page"><?= $ui('icon', ['name' => 'home']) ?>หน้าหลัก</span><span><?= $ui('icon', ['name' => 'list-check']) ?>คำขอ</span><span><?= $ui('icon', ['name' => 'bell']) ?>แจ้งเตือน</span><span><?= $ui('icon', ['name' => 'menu-2']) ?>เพิ่มเติม</span></div>
    </div>
    <div class="stack-sm">
      <h3>PP — List page / Approval Inbox (W-02)</h3>
      <?= $ui('page-header', ['plain' => true, 'title' => 'งานรออนุมัติ', 'status' => null, 'crumbs' => [['หน้าหลัก', '#'], ['งานรออนุมัติ', null]], 'actions' => $btn(['label' => 'อนุมัติที่เลือก', 'variant' => 'primary', 'icon' => 'circle-check'])]) ?>
      <?= $ui('alert', ['tone' => 'info', 'text' => 'หน้ารายการทุกหน้า: Page header → Filter bar → Table (+ Bulk bar) → Pagination; คลิกแถว = Drawer รายละเอียด']) ?>
    </div>
  </div>
</section>

<!-- 13. PENDING -->
<section id="sc-pending" class="sc-section card stack-sm" aria-labelledby="h-pending">
  <h2 id="h-pending"><?= $ui('icon', ['name' => 'hourglass']) ?><?= $e($sections['pending']) ?></h2>
  <p class="text-muted"><?= $e($t('showcase.pending_note')) ?></p>
  <ul>
    <li>Employee / Org Picker (ค้นหาแบบ async ตาม scope) — เฟส 2 (ต้องมีข้อมูลองค์กร + ScopedRepository)</li>
    <li>Searchable Select แบบ type-ahead — เฟส 2</li>
    <li>Date picker แบบปฏิทินที่แสดงวันหยุดจาก Work Calendar + แสดงปี พ.ศ. — เฟส 3 (ตอนนี้ใช้ช่องวันที่มาตรฐานของเบราว์เซอร์)</li>
    <li>Import Wizard (ดาวน์โหลดแม่แบบ → ตรวจ → dry-run) — เฟส 3 พร้อม Import service</li>
    <li>Calendar / Shift Grid — เฟส 5 (M35)</li>
    <li>QR Display / Scanner — เฟส 3U (M27, ต้องประเมินไลบรารี QR ก่อน)</li>
    <li>File Upload (crop/บีบอัด) — เฟส 3 พร้อม Document service</li>
  </ul>
</section>
