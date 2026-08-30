import { PrismaClient, Role } from "@prisma/client";
import { hash } from "bcryptjs";

const prisma = new PrismaClient();

async function main() {
  const passwordHash = await hash("admin123", 10);
  const doctorHash = await hash("doctor123", 10);
  const patientHash = await hash("patient123", 10);

  const admin = await prisma.user.upsert({
    where: { email: "admin@ravansara.ir" },
    update: {},
    create: {
      name: "مدیر سایت",
      email: "admin@ravansara.ir",
      phone: "09120000000",
      passwordHash,
      role: Role.ADMIN,
    },
  });

  const doctorUser = await prisma.user.upsert({
    where: { email: "doctor@ravansara.ir" },
    update: {
      name: "دکتر شیوا گرانمایه پور",
      doctorProfile: {
        update: {
          isApproved: true,
          isActive: true,
          sessionPrice: 3000000,
          bio: "مشاوره تخصصی: فردی، خانواده (پیش از ازدواج و زناشویی)، کودک و نوجوان، تحصیلی و شغلی\nروان‌درمانی: درمان اضطراب، افسردگی و وسواس",
        },
      },
    },
    create: {
      name: "دکتر شیوا گرانمایه پور",
      email: "doctor@ravansara.ir",
      phone: "09121111111",
      passwordHash: doctorHash,
      role: Role.DOCTOR,
      doctorProfile: {
        create: {
          specialty: "روان‌درمانی شناختی-رفتاری",
          bio: "مشاوره تخصصی: فردی، خانواده (پیش از ازدواج و زناشویی)، کودک و نوجوان، تحصیلی و شغلی\nروان‌درمانی: درمان اضطراب، افسردگی و وسواس",
          sessionPrice: 3000000,
          isApproved: true,
          isActive: true,
        },
      },
    },
    include: { doctorProfile: true },
  });

  await prisma.user.upsert({
    where: { email: "patient@ravansara.ir" },
    update: {},
    create: {
      name: "علی رضایی",
      email: "patient@ravansara.ir",
      phone: "09123333333",
      passwordHash: patientHash,
      role: Role.PATIENT,
    },
  });

  const authorId = doctorUser.id;
  await prisma.article.upsert({
    where: { slug: "modiriat-ezterab" },
    update: {},
    create: {
      title: "چگونه اضطراب روزمره را مدیریت کنیم؟",
      slug: "modiriat-ezterab",
      excerpt: "راهکارهای عملی برای کاهش اضطراب در زندگی روزمره و بازگشت به آرامش.",
      content: `اضطراب بخشی طبیعی از زندگی است، اما وقتی بیش از حد شود می‌تواند کیفیت زندگی را کاهش دهد.

## تنفس آگاهانه
هر روز چند دقیقه روی تنفس خود تمرکز کنید. دم عمیق از بینی، نگه‌داشتن کوتاه، و بازدم آهسته از دهان.

## محدود کردن اخبار
مصرف مداوم اخبار منفی سطح اضطراب را بالا می‌برد. زمان مشخصی برای دنبال کردن اخبار تعیین کنید.

## فعالیت بدنی
حتی پیاده‌روی کوتاه روزانه می‌تواند به تنظیم خلق‌وخو کمک کند.

اگر اضطراب شما مداوم است، مراجعه به متخصص روانشناسی می‌تواند مسیر درمان را روشن کند.`,
      published: true,
      publishedAt: new Date(),
      authorId,
    },
  });

  await prisma.article.upsert({
    where: { slug: "khab-salem" },
    update: {},
    create: {
      title: "اهمیت خواب سالم برای سلامت روان",
      slug: "khab-salem",
      excerpt: "خواب کافی یکی از پایه‌های اصلی تعادل هیجانی و تمرکز است.",
      content: `خواب کافی و باکیفیت نقش مهمی در تنظیم هیجانات، حافظه و تمرکز دارد.

## برنامه ثابت
هر شب تقریباً در یک ساعت مشخص بخوابید و صبح در ساعت ثابت بیدار شوید.

## نور آبی
حداقل یک ساعت قبل از خواب استفاده از موبایل و لپ‌تاپ را کم کنید.

## محیط خواب
اتاق تاریک، خنک و آرام به بهبود کیفیت خواب کمک می‌کند.`,
      published: true,
      publishedAt: new Date(),
      authorId,
    },
  });

  if (doctorUser.doctorProfile) {
    const today = new Date();
    for (let i = 1; i <= 7; i++) {
      const d = new Date(today);
      d.setDate(today.getDate() + i);
      const date = d.toISOString().slice(0, 10);
      await prisma.availability.upsert({
        where: { id: `seed-avail-${date}` },
        update: {},
        create: {
          id: `seed-avail-${date}`,
          doctorId: doctorUser.doctorProfile.id,
          date,
          startTime: "10:00",
          endTime: "14:00",
          slotMinutes: 50,
        },
      });
    }
  }

  console.log("Seed OK:", { admin: admin.email, doctor: doctorUser.email });
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
