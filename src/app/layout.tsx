import type { Metadata } from "next";
import { Inter, Merriweather } from "next/font/google";
import { siteConfig } from "@/lib/config";
import { PageLayout } from "@/components/layout/PageLayout";
import "./globals.css";

const bodyFont = Inter({
  variable: "--font-body",
  subsets: ["latin"],
});

const headingFont = Merriweather({
  variable: "--font-heading",
  weight: ["700", "900"],
  subsets: ["latin"],
});

export const metadata: Metadata = {
  metadataBase: new URL(siteConfig.url),
  title: {
    default: `${siteConfig.name} | ${siteConfig.schoolName}`,
    template: `%s | ${siteConfig.name}`,
  },
  description:
    "The official Go PCHS website — the public digital home of Pura Central High School, connecting students, parents, teachers, and the community.",
  openGraph: {
    type: "website",
    siteName: siteConfig.name,
    title: `${siteConfig.name} | ${siteConfig.schoolName}`,
    description:
      "The official Go PCHS website — the public digital home of Pura Central High School.",
    url: siteConfig.url,
  },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${bodyFont.variable} ${headingFont.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">
        <PageLayout>{children}</PageLayout>
      </body>
    </html>
  );
}
