import type { Metadata } from "next";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { siteConfig } from "@/lib/config";

export const metadata: Metadata = {
  title: "Contact Us",
  description: "Get in touch with Pura Central High School.",
};

const fields = [
  { label: "School Address", value: "To be confirmed by the school" },
  { label: "Official Email", value: "To be confirmed by the school" },
  { label: "Contact Number", value: "To be confirmed by the school" },
  { label: "Office Hours", value: "To be confirmed by the school" },
];

export default function ContactPage() {
  return (
    <div className="py-14 sm:py-20">
      <Container>
        <SectionHeading
          eyebrow="Get in Touch"
          title="Contact Us"
          description={`${siteConfig.schoolName} · ${siteConfig.division}, ${siteConfig.region}. Official contact details below will be filled in once verified by the school administration.`}
        />

        <div className="mt-10 grid gap-8 lg:grid-cols-2">
          <dl className="grid gap-4 sm:grid-cols-2">
            {fields.map((field) => (
              <div
                key={field.label}
                className="rounded-card border border-dashed border-black/15 bg-pchs-cream p-4"
              >
                <dt className="text-xs font-bold uppercase tracking-wide text-pchs-gold-600">
                  {field.label}
                </dt>
                <dd className="mt-1 text-sm italic text-black/50">
                  {field.value}
                </dd>
              </div>
            ))}
          </dl>

          <div className="flex min-h-[220px] items-center justify-center rounded-card border border-black/5 bg-pchs-green-900/5 text-sm text-black/40">
            Map integration placeholder
          </div>
        </div>
      </Container>
    </div>
  );
}
