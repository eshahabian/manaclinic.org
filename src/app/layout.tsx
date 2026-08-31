import type { Metadata } from "next";
import { Vazirmatn } from "next/font/google";
import { Providers } from "@/components/providers";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { SiteTestsSidebar } from "@/components/site-tests-sidebar";
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
          <SiteTestsSidebar />
          <div className="site-layer">
            <SiteHeader />
            <main>{children}</main>
            <SiteFooter />
          </div>
        </Providers>
      </body>
    </html>
  );
}
