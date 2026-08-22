import type { Metadata } from "next";
import Image from "next/image";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { Badge } from "@/components/ui/Badge";
import { Card, CardBody } from "@/components/ui/Card";
import { historicalFaculty } from "@/data/faculty";

export const metadata: Metadata = {
  title: "Faculty & Staff",
  description:
    "Meet the founding faculty of Pura Central High School, archived from the school's 2005 founding records.",
};

export default function FacultyStaffPage() {
  return (
    <div className="pb-14 sm:pb-20">
      <div className="relative aspect-[21/9] w-full overflow-hidden">
        <Image
          src="/assets/images/faculty/faculty-placeholder.jpg"
          alt="Illustrative placeholder photo of PCHS faculty and staff"
          fill
          priority
          sizes="100vw"
          className="object-cover"
        />
      </div>
      <p className="bg-pchs-cream py-2 text-center text-xs text-black/50">
        Illustrative placeholder photo — not an actual photograph of PCHS faculty.
      </p>

      <Container className="pt-10">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <SectionHeading
            eyebrow="About PCHS"
            title="Faculty & Staff"
            description="A current, verified faculty directory is being prepared. Shown below is the archival founding faculty roster from the school's 2005 records."
          />
          <Badge tone="gold">Archival — 2005 Records</Badge>
        </div>

        <div className="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {historicalFaculty.map((member) => (
            <Card key={member.name}>
              <CardBody>
                <p className="font-display text-base font-bold text-pchs-green-900">
                  {member.name}
                </p>
                <p className="mt-1 text-sm text-black/60">{member.position}</p>
                {member.department && (
                  <p className="mt-2 text-xs font-semibold uppercase tracking-wide text-pchs-gold-600">
                    {member.department}
                  </p>
                )}
              </CardBody>
            </Card>
          ))}
        </div>
      </Container>
    </div>
  );
}
