<?php $pages = max(1, (int)ceil($total / 50)); ?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div><h1>دعوت‌های نیازمند تأیید</h1><p class="text-muted">کل در انتظار: <?= fa_digits($total) ?></p></div>
    <a href="<?= url('admin/users') ?>" class="btn btn-secondary">بازگشت به کاربران</a>
</div>
<div class="card"><div class="card-body">
    <p>کاربران قدیمی که هنگام به‌روزرسانی منتظر تأیید بوده‌اند و دعوت‌های جدیدی که گزینه تأیید مدیر برایشان فعال بوده، در این صف هستند. تأیید فقط شرط مدیر را تکمیل می‌کند؛ سایر شرایط و سقف‌ها و نرخ فعال زمان تأیید نیز اعمال می‌شوند. رد، پاداش را آزاد نمی‌کند.</p>
    <p>انتخاب همه در این صفحه فقط شامل همین صفحه است. گزینه «همه صفحات» همه موارد منتظر تا زمان باز شدن صفحه را در دسته‌های کوچک پردازش می‌کند. برای پرداخت از پایگاه داده پشتیبان داشته باشید.</p>
    <?php if (!$rows): ?><div class="empty">در این صفحه موردی در انتظار نیست.</div><?php else: ?>
    <form id="verification-form" method="post" action="<?= url('admin/users/verification') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="through" value="<?= (int)$through ?>">
        <label class="checkbox"><input type="checkbox" id="select-page"> انتخاب همه این صفحه</label>
        <label class="checkbox"><input type="checkbox" id="select-all" name="scope" value="all"> انتخاب همه صفحات (<?= fa_digits($total) ?> مورد)</label>
        <div class="table-wrap"><table class="table"><thead><tr><th>انتخاب</th><th>کاربر</th><th>شناسه</th><th>معرف</th><th>وضعیت حساب</th><th>تاریخ</th></tr></thead><tbody>
        <?php foreach ($rows as $u): ?>
        <tr><td><input class="user-choice" type="checkbox" name="ids[]" value="<?= (int)$u['id'] ?>" aria-label="انتخاب کاربر <?= (int)$u['id'] ?>"></td>
            <td><a href="<?= url('admin/users/'.(int)$u['id']) ?>"><?= e(trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: ('کاربر #'.$u['id'])) ?></a> <?= !empty($u['username']) ? '@'.e($u['username']) : '' ?></td>
            <td><?= fa_digits((int)$u['id']) ?></td><td><a href="<?= url('admin/users/'.(int)$u['referred_by']) ?>"><?= fa_digits((int)$u['referred_by']) ?></a></td><td><?= e((string)$u['status']) ?></td><td><?= fa_date($u['joined_at']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <button class="btn btn-success" type="submit" name="decision" value="verified">تأیید انتخاب‌شده‌ها</button>
        <button class="btn btn-danger" type="submit" name="decision" value="rejected">رد انتخاب‌شده‌ها</button>
        <div id="verification-result" role="status" aria-live="polite" class="mt-2"></div>
    </form>
    <div class="pagination"><a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= url('admin/users/verification?page='.max(1,$page-1)) ?>">قبلی</a><span class="page-info">صفحه <?= fa_digits($page) ?> از <?= fa_digits($pages) ?></span><a class="page-link <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= url('admin/users/verification?page='.min($pages,$page+1)) ?>">بعدی</a></div>
    <?php endif; ?>
</div></div>
<script>
(function () {
 const form=document.getElementById('verification-form'); if (!form) return;
 const choices=Array.from(form.querySelectorAll('.user-choice')), page=document.getElementById('select-page'), all=document.getElementById('select-all'), output=document.getElementById('verification-result');
 page.addEventListener('change',()=>choices.forEach(c=>c.checked=page.checked));
 form.addEventListener('submit', async function(e) {
   e.preventDefault(); const decision=e.submitter && e.submitter.value;
   if (!decision || (!all.checked && !choices.some(c=>c.checked))) { output.textContent='ابتدا کاربران را انتخاب کنید.'; return; }
   if (!confirm((decision==='verified'?'تأیید':'رد')+' '+(all.checked?'همه موارد منتظر در همه صفحات':'کاربران انتخاب‌شده')+' انجام شود؟ تأیید ممکن است باعث واریز با شرایط فعلی شود.')) return;
   const data=new FormData(form); data.set('decision',decision); if (!all.checked) data.delete('scope');
   const buttons=Array.from(form.querySelectorAll('button')); buttons.forEach(b=>b.disabled=true);
   let cursor=0, done=false, changed=0, qualified=0, skipped=0, conflicts=[], errors=[];
   try {
     while (!done) {
       data.set('after',String(cursor));
       const res=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
       const r=await res.json(); if (!res.ok || !r.ok) throw new Error(r.error || 'خطای سرور');
       changed+=r.changed; qualified+=r.qualified; skipped+=r.skipped; conflicts.push(...r.conflicts); errors.push(...r.errors); cursor=r.next; done=r.done;
       output.textContent='تغییر وضعیت: '+changed+'؛ واجد شرایط: '+qualified;
     }
     output.textContent+='؛ ردشده از پردازش: '+skipped+'؛ سابقه نیازمند بررسی: '+conflicts.length+(conflicts.length?' (شناسه: '+conflicts.join('، ')+')':'')+'؛ خطا: '+errors.length+(errors.length?' (شناسه: '+errors.join('، ')+')':'')+'. برای مشاهده صف تازه، صفحه را بازخوانی کنید.';
   } catch(err) { output.textContent+=' — پردازش متوقف شد: '+err.message+'. بازخوانی و اجرای دوباره برای موارد باقی‌مانده ممکن است.'; }
   finally { buttons.forEach(b=>b.disabled=false); }
 });
})();
</script>
