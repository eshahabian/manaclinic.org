export type PsychTest = {
  slug: string;
  title: string;
  abbr: string;
  category: string;
  description: string;
  duration: string;
  ready: boolean;
};

export const psychTestsCatalog: PsychTest[] = [
  {
    slug: "bdi",
    title: "پرسشنامه افسردگی بک",
    abbr: "BDI-II",
    category: "خلقی",
    description: "سنجش شدت علائم افسردگی در هفتهٔ اخیر.",
    duration: "۵–۱۰ دقیقه",
    ready: false,
  },
  {
    slug: "bai",
    title: "پرسشنامه اضطراب بک",
    abbr: "BAI",
    category: "اضطراب",
    description: "ارزیابی شدت علائم جسمی و روانی اضطراب.",
    duration: "۵–۱۰ دقیقه",
    ready: false,
  },
  {
    slug: "dass-21",
    title: "مقیاس افسردگی، اضطراب و استرس",
    abbr: "DASS-21",
    category: "غربالگری",
    description: "سه بعد افسردگی، اضطراب و استرس را هم‌زمان می‌سنجد.",
    duration: "۵ دقیقه",
    ready: false,
  },
  {
    slug: "ghq",
    title: "پرسشنامه سلامت عمومی",
    abbr: "GHQ-28",
    category: "سلامت عمومی",
    description:
      "غربالگری وضعیت روانی و علائم ناراحتی در چهار هفتهٔ اخیر.",
    duration: "۱۰ دقیقه",
    ready: false,
  },
  {
    slug: "scl-90",
    title: "فهرست علائم SCL-90",
    abbr: "SCL-90-R",
    category: "علائم",
    description:
      "بررسی طیف علائم روانی از اضطراب تا افسردگی و وسواس.",
    duration: "۱۵–۲۰ دقیقه",
    ready: false,
  },
  {
    slug: "pss",
    title: "مقیاس استرس ادراک‌شده",
    abbr: "PSS",
    category: "استرس",
    description: "میزان استرس درک‌شده در یک ماه گذشته.",
    duration: "۵ دقیقه",
    ready: false,
  },
  {
    slug: "neo",
    title: "پرسشنامه شخصیت پنج‌گانه",
    abbr: "NEO-FFI",
    category: "شخصیت",
    description: "پروفایل شخصیت بر پایهٔ پنج عامل اصلی (بیگ فایو).",
    duration: "۱۵ دقیقه",
    ready: false,
  },
  {
    slug: "swls",
    title: "مقیاس رضایت از زندگی",
    abbr: "SWLS",
    category: "رفاه ذهنی",
    description: "سنجش میزان رضایت کلی فرد از زندگی.",
    duration: "۲ دقیقه",
    ready: false,
  },
  {
    slug: "mmpi",
    title: "آزمون شخصیت مینه‌سوتا",
    abbr: "MMPI-2",
    category: "بالینی",
    description:
      "ارزیابی جامع شخصیت و علائم روانی؛ تفسیر تخصصی لازم دارد.",
    duration: "۶۰–۹۰ دقیقه",
    ready: false,
  },
  {
    slug: "ybocs",
    title: "مقیاس وسواس ییل-براون",
    abbr: "Y-BOCS",
    category: "وسواس",
    description: "سنجش شدت افکار و اعمال اجباری (وسواس).",
    duration: "۱۰ دقیقه",
    ready: false,
  },
];

export function getPsychTestBySlug(slug: string): PsychTest | undefined {
  return psychTestsCatalog.find((test) => test.slug === slug);
}
