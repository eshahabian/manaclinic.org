<?php
declare(strict_types=1);

require_once __DIR__ . '/bdi.php';

/** Each public launch is explicit. Owning/licensing a form never enables a feature. */
function psych_assessment_enabled(string $slug): bool
{
    global $config;
    return ($config['psych_test_enabled'][$slug] ?? ($slug === 'bdi')) === true;
}

function psych_assessment_ai_enabled(string $slug): bool
{
    global $config;
    return psych_assessment_enabled($slug) && ($config['psych_test_ai_enabled'][$slug] ?? ($slug === 'bdi')) === true;
}

/** Version-specific rules; no question text or invented population norms. */
function psych_assessment_specs(): array
{
    return [
        'bai' => ['version'=>'BAI','count'=>21,'min'=>0,'max'=>3,'mode'=>'items','period'=>'هفته اخیر، شامل امروز',
            'method'=>'جمع ۲۱ امتیاز صفر تا سه؛ دامنه صفر تا ۶۳. راهنمای عمومی شدت: صفر تا ۷ حداقل، ۸ تا ۱۵ خفیف، ۱۶ تا ۲۵ متوسط، ۲۶ تا ۶۳ شدید.',
            'sources'=>['راهنمای ورود امتیاز ناشر'=>'https://qglobal.pearsonclinical.com/qg/static/Product/sv/BAI/BAI_Enter_Scores.htm','پژوهش کاربرد محدوده‌های شدت'=>'https://pmc.ncbi.nlm.nih.gov/articles/PMC7537014/']],
        'dass-21' => ['version'=>'DASS-21-adult','count'=>21,'min'=>0,'max'=>3,'mode'=>'items','period'=>'هفته اخیر',
            'method'=>'برای هر یک از سه مقیاس، ۷ امتیاز جمع و در ۲ ضرب می‌شود؛ دامنه هر مقیاس صفر تا ۴۲ است. امتیازهای خام و دوبرابرشده جدا نمایش داده می‌شوند. برچسب شدت، تشخیص اختلال نیست. قواعد DASS-Y یا نسخه ۴۲سؤالی متفاوت است.',
            'sources'=>['راهنمای سازندگان در UNSW'=>'https://dass.psy.unsw.edu.au/DASSFAQ.htm','کلید مقیاس‌های نسخه کوتاه'=>'https://faculty.ung.edu/djspence/nsf/materials/appendixc_surveys.pdf']],
        'ghq' => ['version'=>'GHQ-28','count'=>28,'min'=>0,'max'=>3,'mode'=>'items','period'=>'چند هفته اخیر',
            'method'=>'روش لیکرت: امتیازهای صفر، یک، دو، سه و جمع صفر تا ۸۴. روش GHQ: همان چهار رتبه به صفر، صفر، یک، یک تبدیل می‌شوند؛ جمع صفر تا ۲۸. چهار مقیاس ۷سؤالی جدا محاسبه می‌شوند. نقطه برش بدون انتخاب هنجار مناسب اعلام نمی‌شود.',
            'safety_items'=>[24,25,27,28],
            'sources'=>['پژوهش اعتبارسنجی GHQ-28'=>'https://pmc.ncbi.nlm.nih.gov/articles/PMC3437115/','ساختار و سؤال‌های حساس'=>'https://www.aseanjournalofpsychiatry.org/articles/psychometric-properties-and-factor-invariance-for-the-general-health-questionnaire-ghq28-study-in-peruvian-population-exposed-to-t-69790.html']],
        'scl-90' => ['version'=>'SCL-90-R','count'=>90,'min'=>0,'max'=>4,'mode'=>'items','period'=>'هفت روز اخیر، شامل امروز',
            'method'=>'۹ مقیاس با میانگین سؤال‌های مربوط محاسبه می‌شوند. GSI میانگین هر ۹۰ امتیاز، PST تعداد پاسخ‌های بالاتر از صفر و PSDI مجموع امتیازها تقسیم بر PST است. اگر PST صفر باشد، PSDI تعریف‌نشده است و صفر ساختگی نمی‌گیرد. تبدیل به T یا تعیین وضعیت بالینی نیازمند هنجار مناسب است.',
            'safety_items'=>[15],
            'sources'=>['کلید ۹ مقیاس در پژوهش اصلی'=>'https://pmc.ncbi.nlm.nih.gov/articles/PMC3626675/','معرفی شاخص‌ها از ناشر'=>'https://www.pearsonclinical.co.uk/content/dam/school/global/clinical/us/assets/scl-90-r/scl-90-r-scales.pdf','پژوهش شاخص‌های کلی'=>'https://www.scielo.cl/scielo.php?pid=S0718-48082008000100004&script=sci_arttext']],
        'pss' => ['version'=>'PSS-10','count'=>10,'min'=>0,'max'=>4,'mode'=>'items','period'=>'یک ماه اخیر',
            'method'=>'نسخه انتخاب‌شده PSS-10 است. پاسخ خام سؤال‌های ۴، ۵، ۷ و ۸ معکوس می‌شود: صفر با چهار و یک با سه جابه‌جا می‌شود و دو ثابت می‌ماند. سپس ۱۰ امتیاز جمع می‌شوند؛ دامنه صفر تا ۴۰. طبق توضیح سازنده، نقطه برش تشخیصی ثابت ندارد.',
            'sources'=>['سازنده مقیاس در دانشگاه کارنگی ملون'=>'https://www.cmu.edu/dietrich/psychology/stress-immunity-disease-lab/scales/index.html']],
        'neo' => ['version'=>'NEO-FFI-60-original','count'=>60,'min'=>0,'max'=>4,'mode'=>'profile','period'=>'ویژگی‌های معمول فرد',
            'method'=>'پنج عامل جداگانه‌اند؛ نمره کل شخصیت ساخته نمی‌شود. هر عامل در نسخه ۶۰سؤالی ۱۲ ماده و نمره خام صفر تا ۴۸ دارد. کلید معکوس و هنجار باید دقیقاً متعلق به همان نسخه باشد. فعلاً نمره خام پنج عامل از گزارش نمره‌گذاری‌شده وارد می‌شود؛ موتور پاسخ‌به‌پاسخ پس از تأمین کلید تأییدشده نسخه قابل اتصال است. بدون هنجار، برچسب بالا/پایین جمعیتی یا T ساخته نمی‌شود.',
            'sources'=>['پژوهشگر NEO-FFI در دانشگاه اورگن'=>'https://pages.uoregon.edu/gsaucier/NEO-FFI%20subcomponent%20norms%20and%20scoring.htm','تفاوت نسخه‌ها و گزارش ناشر PAR'=>'https://www.parinc.com/docs/default-source/product-resources/neo-ffi-3_self_score_sample.pdf?sfvrsn=6c1b8b2f_7']],
        'swls' => ['version'=>'SWLS-5-7point','count'=>5,'min'=>1,'max'=>7,'mode'=>'items','period'=>'ارزیابی کلی زندگی',
            'method'=>'جمع ۵ پاسخ یک تا هفت، بدون معکوس‌سازی؛ دامنه ۵ تا ۳۵. راهنمای توصیفی دینر: ۵ تا ۹ بسیار پایین، ۱۰ تا ۱۴ پایین، ۱۵ تا ۱۹ کمی پایین‌تر از میانگین مرجع، ۲۰ تا ۲۴ در محدوده میانگین مرجع، ۲۵ تا ۲۹ بالا، ۳۰ تا ۳۵ بسیار بالا. این توصیف‌ها تشخیص بیماری یا هنجار اختصاصی ایران نیستند.',
            'sources'=>['صفحه سازنده SWLS'=>'https://labs.psychology.illinois.edu/~ediener/SWLS.html','راهنمای توصیفی دینر'=>'https://labs.psychology.illinois.edu/~ediener/Documents/Understanding%20SWLS%20Scores.pdf']],
        'mmpi' => ['version'=>'MMPI-2-567','count'=>567,'min'=>0,'max'=>1,'mode'=>'clinical','period'=>'طبق راهنمای اجرای MMPI-2',
            'method'=>'MMPI-2 با جمع ساده پاسخ‌ها نمره‌گذاری نمی‌شود. کلید رسمی، مقیاس‌های اعتبار، اصلاحات لازم و جداول تبدیل وابسته به نسخه و هنجار ضروری‌اند. این بخش مخصوص متخصص برای نمایش نمره‌های T از گزارش رسمی و وضعیت اعتبار همان گزارش است. امتیازدهی ۵۶۷ پاسخ و نتیجه تشخیصی تا اتصال موتور رسمی ارائه نمی‌شود؛ نسخه RF و MMPI-3 قابل جایگزینی نیستند.',
            'sources'=>['راهنمای ناشر MMPI-2 و تقدم بررسی اعتبار'=>'https://www.pearsonassessments.com/en-us/Store/Professional-Assessments/Personality-%26-Biopsychosocial/Minnesota-Multiphasic-Personality-Inventory-2/p/100000461']],
        'ybocs' => ['version'=>'Y-BOCS-original-10','count'=>10,'min'=>0,'max'=>4,'mode'=>'items','period'=>'هفته اخیر',
            'method'=>'۱۰ امتیاز صفر تا چهار؛ سؤال‌های ۱ تا ۵ افکار وسواسی و ۶ تا ۱۰ اعمال اجباری‌اند. هر بخش صفر تا ۲۰ و جمع صفر تا ۴۰ است. محدوده‌های توصیفی: صفر تا ۷ زیرآستانه، ۸ تا ۱۵ خفیف، ۱۶ تا ۲۳ متوسط، ۲۴ تا ۳۱ شدید، ۳۲ تا ۴۰ بسیار شدید. این قواعد برای نسخه اصلی است، نه Y-BOCS-II. نمره‌ها باید طبق راهنمای معتبر و ترجیحاً با متخصص تعیین شوند.',
            'sources'=>['ساختار و تفاوت نسخه خودگزارشی با مصاحبه'=>'https://pmc.ncbi.nlm.nih.gov/articles/PMC3590426/','پژوهش کاربرد محدوده‌های شدت'=>'https://pmc.ncbi.nlm.nih.gov/articles/PMC3843018/']],
    ];
}

function psych_integer($value, int $min, int $max): int
{
    if (!(is_int($value) || (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)))) {
        throw new InvalidArgumentException('فقط عدد صحیح در دامنه تعیین‌شده پذیرفته می‌شود.');
    }
    $n=(int)$value;
    if($n<$min || $n>$max) throw new InvalidArgumentException('امتیاز خارج از دامنه مجاز است.');
    return $n;
}

function psych_item_values(array $values, int $count, int $min, int $max): array
{
    if(count($values)!==$count || array_keys($values)!==range(1,$count)) throw new InvalidArgumentException('پاسخ همه سؤال‌ها، بدون مورد اضافه، لازم است.');
    return array_map(static fn($v)=>psych_integer($v,$min,$max),$values);
}

function psych_severity(int $value, array $upper): string
{
    $labels=['حداقل / محدوده معمول','خفیف','متوسط','شدید','بسیار شدید'];
    foreach($upper as $index=>$max) if($value<=$max) return $labels[$index];
    throw new InvalidArgumentException('امتیاز نامعتبر است.');
}

function psych_metric(string $key, string $label, $value, int $max, string $band='بدون حد برش تشخیصی'): array
{
    return ['key'=>$key,'label'=>$label,'value'=>$value,'max'=>$max,'band'=>$band];
}

function psych_scl_keys(): array
{
    return [
        'som'=>['نشانه‌های جسمانی',[1,4,12,27,40,42,48,49,52,53,56,58]],
        'oc'=>['نشانه‌های وسواسی',[3,9,10,28,38,45,46,51,55,65]],
        'is'=>['حساسیت بین‌فردی',[6,21,34,36,37,41,61,69,73]],
        'dep'=>['نشانه‌های افسردگی',[5,14,15,20,22,26,29,30,31,32,54,71,79]],
        'anx'=>['نشانه‌های اضطراب',[2,17,23,33,39,57,72,78,80,86]],
        'hos'=>['خصومت',[11,24,63,67,74,81]],
        'phob'=>['اضطراب هراسی',[13,25,47,50,70,75,82]],
        'par'=>['افکار بدگمانانه',[8,18,43,68,76,83]],
        'psy'=>['نشانه‌های مقیاس روان‌پریشی',[7,16,35,62,77,84,85,87,88,90]],
    ];
}

function psych_profile_labels(string $slug): array
{
    return $slug==='neo' ? ['N'=>'روان‌رنجورخویی','E'=>'برون‌گرایی','O'=>'گشودگی','A'=>'توافق‌پذیری','C'=>'وظیفه‌شناسی']
        : ['VRIN'=>'VRIN','TRIN'=>'TRIN','F'=>'F','L'=>'L','K'=>'K','Hs'=>'Hs','D'=>'D','Hy'=>'Hy','Pd'=>'Pd','Mf'=>'Mf','Pa'=>'Pa','Pt'=>'Pt','Sc'=>'Sc','Ma'=>'Ma','Si'=>'Si'];
}

/** Every calculation is server-side and reproducible independently of the model. */
function psych_assessment_score(string $slug, array $input, array $context=[]): array
{
    $spec=psych_assessment_specs()[$slug]??null;
    if(!$spec)throw new InvalidArgumentException('نسخه آزمون شناخته‌شده نیست.');
    if(($context['version']??'')!==$spec['version'])throw new InvalidArgumentException('نسخه پاسخنامه با قواعد امتیازدهی یکسان نیست.');
    $result=['slug'=>$slug,'version'=>$spec['version'],'total'=>null,'total_max'=>null,'label'=>'پروفایل چندمقیاسی','axes'=>[],'metrics'=>[],
        'answers'=>[],'safety_followup'=>false,'ai_allowed'=>true,'origin'=>'calculated_item_scores'];
    if(in_array($spec['mode'],['profile','clinical'],true)){
        $fields=psych_profile_labels($slug);
        if(array_keys($input)!==array_keys($fields))throw new InvalidArgumentException('همه نمره‌های پروفایل با نام و ترتیب معتبر لازم‌اند.');
        if(($context['official_report']??false)!==true)throw new InvalidArgumentException('این بخش نیازمند نمره‌های گزارش رسمی همان نسخه است.');
        if($slug==='mmpi' && ($context['clinical_operator']??false)!==true)throw new InvalidArgumentException('این بخش فقط برای متخصص است.');
        $max=$slug==='neo'?48:120;
        foreach($fields as $key=>$label){$n=psych_integer($input[$key],0,$max);$result['answers'][$key]=$n;$result['axes'][]=psych_metric($key,$label,$n,$max,'نمره خام عامل؛ بدون هنجار');}
        $result['origin']='entered_official_profile';
        if($slug==='mmpi'){
            $validity=$context['validity']??'';
            if(!in_array($validity,['valid','invalid','uncertain'],true))throw new InvalidArgumentException('وضعیت اعتبار گزارش رسمی را مشخص کنید.');
            $result['validity']=$validity;$result['ai_allowed']=$validity==='valid';
            $result['label']=$validity==='valid'?'اعتبار قابل‌تفسیر، طبق گزارش واردشده':($validity==='invalid'?'گزارش نامعتبر؛ بدون تحلیل':'اعتبار نامشخص؛ بدون تحلیل');
            foreach($result['axes'] as &$axis)$axis['band']='نمره T واردشده؛ تشخیص خودکار ارائه نمی‌شود';unset($axis);
        }
        return $result;
    }
    $values=psych_item_values($input,$spec['count'],$spec['min'],$spec['max']);
    $result['answers']=$values;
    foreach($spec['safety_items']??[] as $id)if($values[$id]>0)$result['safety_followup']=true;
    $sum=static fn(array $ids):int=>array_sum(array_intersect_key($values,array_flip($ids)));
    if($slug==='bai'){
        $result['total']=array_sum($values);$result['total_max']=63;
        $result['label']=psych_severity($result['total'],[7,15,25,63]);
    }elseif($slug==='dass-21'){
        $maps=['depression'=>['افسردگی',[3,5,10,13,16,17,21],[9,13,20,27,42]],
            'anxiety'=>['اضطراب',[2,4,7,9,15,19,20],[7,9,14,19,42]],
            'stress'=>['استرس',[1,6,8,11,12,14,18],[14,18,25,33,42]]];
        foreach($maps as $key=>[$label,$ids,$cuts]){
            $raw=$sum($ids);$metric=psych_metric($key,$label,$raw*2,42,psych_severity($raw*2,$cuts));$metric['raw']=$raw;$result['axes'][]=$metric;
        }
        $result['label']='سه مقیاس مستقل؛ امتیازهای دوبرابرشده';
    }elseif($slug==='ghq'){
        $binary=array_map(static fn($v)=>$v<2?0:1,$values);
        foreach(['نشانه‌های جسمانی','اضطراب و بی‌خوابی','اختلال عملکرد اجتماعی','نشانه‌های افسردگی شدید'] as $index=>$label){
            $ids=range($index*7+1,$index*7+7);$result['axes'][]=psych_metric('ghq'.($index+1),$label,$sum($ids),21);
        }
        $result['total']=array_sum($values);$result['total_max']=84;$result['label']='جمع لیکرت؛ بدون تعیین وضعیت بالینی';
        $result['metrics'][]=psych_metric('binary','جمع با روش GHQ (۰،۰،۱،۱)',array_sum($binary),28);
    }elseif($slug==='scl-90'){
        foreach(psych_scl_keys() as $key=>[$label,$ids])$result['axes'][]=psych_metric($key,$label,round($sum($ids)/count($ids),4),4);
        $total=array_sum($values);$pst=count(array_filter($values,static fn($v)=>$v>0));
        $result['metrics']=[psych_metric('gsi','GSI · میانگین کل',round($total/90,4),4),psych_metric('pst','PST · تعداد پاسخ‌های مثبت',$pst,90),psych_metric('psdi','PSDI · شدت پاسخ‌های مثبت',$pst?round($total/$pst,4):null,4)];
        $result['label']='پروفایل خام؛ نیازمند هنجار برای تفسیر بالینی';
    }elseif($slug==='pss'){
        $scored=$values;foreach([4,5,7,8] as $id)$scored[$id]=4-$scored[$id];
        $result['processed_answers']=$scored;$result['total']=array_sum($scored);$result['total_max']=40;$result['label']='استرس ادراک‌شده؛ بدون حد برش تشخیصی';
    }elseif($slug==='swls'){
        $total=array_sum($values);$result['total']=$total;$result['total_max']=35;
        $result['label']=$total<=9?'رضایت بسیار پایین':($total<=14?'رضایت پایین':($total<=19?'کمی پایین‌تر از میانگین مرجع':($total<=24?'محدوده میانگین مرجع':($total<=29?'رضایت بالا':'رضایت بسیار بالا'))));
    }elseif($slug==='ybocs'){
        $result['total']=array_sum($values);$result['total_max']=40;$result['label']=psych_severity($result['total'],[7,15,23,31,40]);
        $result['axes']=[psych_metric('obsessions','افکار وسواسی',$sum(range(1,5)),20),psych_metric('compulsions','اعمال اجباری',$sum(range(6,10)),20)];
    }
    return $result;
}

/** Licensed NEO keys are version-bound, with complete and non-overlapping coverage. */
function psych_neo_score_items(array $input, array $key): array
{
    if(($key['version']??'')!=='NEO-FFI-60-original' || ($key['verified']??false)!==true || trim((string)($key['source']??''))==='')throw new InvalidArgumentException('کلید تأییدشده نسخه NEO لازم است.');
    $values=psych_item_values($input,60,0,4);$scores=array_fill_keys(['N','E','O','A','C'],0);$counts=$scores;
    if(array_keys($key['items']??[])!==range(1,60))throw new InvalidArgumentException('کلید NEO ناقص است.');
    foreach($key['items'] as $id=>$entry){
        if(!is_array($entry)||!isset($scores[$entry['domain']??''])||!is_bool($entry['reverse']??null))throw new InvalidArgumentException('کلید NEO معتبر نیست.');
        $scores[$entry['domain']]+=$entry['reverse']?4-$values[$id]:$values[$id];$counts[$entry['domain']]++;
    }
    if(array_values($counts)!==[12,12,12,12,12])throw new InvalidArgumentException('تعداد ماده‌های هر عامل NEO باید ۱۲ باشد.');
    return psych_assessment_score('neo',$scores,['version'=>'NEO-FFI-60-original','official_report'=>true]);
}

function psych_assessment_form(string $slug): ?array
{
    global $config;
    $spec=psych_assessment_specs()[$slug]??null;$form=$config['psych_test_forms'][$slug]??null;
    if(!$spec || $spec['mode']!=='items' || !is_array($form) || ($form['online_use_authorized']??false)!==true
        || ($form['version']??'')!==$spec['version'] || trim((string)($form['source']??''))===''
        || trim((string)($form['instructions']??''))==='' || array_keys($form['items']??[])!==range(1,$spec['count']))return null;
    foreach($form['items'] as $item){
        if(!is_array($item)||!is_string($item['text']??null)||trim($item['text'])===''||!is_array($item['options']??null)||array_keys($item['options'])!==range(0,$spec['max']-$spec['min']))return null;
        foreach($item['options'] as $index=>$option)if(!is_array($option)||!is_string($option['text']??null)||trim($option['text'])===''||($option['value']??null)!==$index+$spec['min'])return null;
    }
    return $form;
}

function psych_safety_message(): string
{
    return 'بخشی از پاسخ‌های شما نیازمند گفت‌وگوی مستقیم و سریع با متخصص است، حتی اگر امتیاز کل پایین باشد. این پاسخ‌ها به‌تنهایی میزان خطر را مشخص نمی‌کنند. اگر همین حالا احتمال آسیب به خود را می‌دهید یا نمی‌توانید ایمن بمانید، تنها نمانید؛ از فرد مورد اعتماد بخواهید همراهتان باشد و با خدمات اضطراری محل یا نزدیک‌ترین اورژانس تماس بگیرید. منتظر هوش مصنوعی نمانید؛ این صفحه توسط تیم درمان به‌صورت زنده پایش نمی‌شود.';
}

function psych_assessment_ai_messages(array $result): array
{
    // Only call after recalculation by psych_assessment_score in the request handler.
    if(!empty($result['safety_followup']) || empty($result['ai_allowed']))throw new DomainException('پیگیری انسانی لازم است؛ تحلیل خودکار ارائه نمی‌شود.');
    $spec=psych_assessment_specs()[$result['slug']]??null;if(!$spec)throw new InvalidArgumentException('نسخه نامعتبر است.');
    return [
        ['role'=>'system','content'=>'همراه آموزشی فارسی‌زبان مانا هستید. فقط JSON معتبر با summary (رشته کوتاه)، observations (۲ یا ۳ رشته کوتاه)، next_steps (۲ یا ۳ رشته کوتاه) بده. قواعد و امتیازهای ورودی از سرور قطعی‌اند؛ تغییرشان نده. نسخه و روش آزمون را رعایت کن. هیچ متن سؤال یا گزینه تولید نکن. تشخیص، دارو، دوز، علت قطعی، درصد ابتلا، تیپ شخصیتی قطعی، سابقه یا روند تغییر حدس نزن. نتیجه غربالگری است. امتیاز پایین را نبود مشکل یا ایمنی قطعی ندان. برای PSS، GHQ، SCL و نمره خام NEO حد برش، T، صدک یا بالا/پایین جمعیتی نساز. مقیاس‌های SCL نشانه‌اند، نه تشخیص اسکیزوفرنی یا اختلال شخصیت. SWLS رضایت از زندگی است، نه شدت بیماری. MMPI فقط توضیح آموزشی نمره‌های گزارش رسمی متخصص است؛ هیچ تشخیص یا کدتیپ استخراج نکن. داده‌های شخصی در ورودی نیست؛ چیزی درباره زندگی فرد نساز. معنی کاربردی و محدودیت‌ها را در لحن مهربان بگو و قدم‌های کوچک و ارزیابی متخصص در صورت اختلال عملکرد پیشنهاد کن. در summary روشن کن خروجی هوش مصنوعی است و جای متخصص را نمی‌گیرد.'],
        ['role'=>'user','content'=>json_encode(['version'=>$result['version'],'period'=>$spec['period'],'method'=>$spec['method'],
            'origin'=>$result['origin'],'total'=>$result['total'],'total_max'=>$result['total_max'],'label'=>$result['label'],
            'axes'=>$result['axes'],'metrics'=>$result['metrics'],'validity'=>$result['validity']??null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)],
    ];
}
