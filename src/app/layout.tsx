import type { Metadata } from "next";
import { Vazirmatn } from "next/font/google";
import { Providers } from "@/components/providers";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { SiteContentShell } from "@/components/site-content-shell";
import { ParticleNetwork } from "@/components/particle-network";
import "./globals.css";

const vazirmatn = Vazirmatn({
  subsets: ["arabic"],
  variable: "--font-vazirmatn",
  display: "swap",
});

export const metadata: Metadata = {
  title: {
    default: "مانا کلینیک | روانشناسی و نوبت‌دهی آنلاین",
    template: "%s | مانا کلینیک",
  },
  description:
    "مانا کلینیک — مقالات روانشناسی، رزرو نوبت آنلاین با روانشناسان و پرداخت امن با زرین‌پال",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fa" dir="rtl">
      <body className={`${vazirmatn.variable} antialiased`}>
        <Providers>
          <ParticleNetwork />
          <div className="site-layer">
            <SiteHeader />
            <main>
              <SiteContentShell>{children}</SiteContentShell>
            </main>
            <SiteFooter />
          </div>
        </Providers>
      </body>
    </html>
  );
}
