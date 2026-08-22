import type { Metadata } from "next";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { PortalDirectory } from "@/components/portal/PortalDirectory";

export const metadata: Metadata = {
  title: "Portals",
  description:
    "Access the Go PCHS ecosystem — LMS, Attendance, Grading, Parents, Teacher's, Student, Publication, and Alumni portals.",
};

export default function PortalsPage() {
  return (
    <div className="bg-pchs-green-950 py-14 text-white sm:py-20">
      <Container>
        <SectionHeading
          eyebrow="Go PCHS"
          title="One Login. The Whole School."
          description="Every Go PCHS service — learning, attendance, grades, and communication — lives on its own secure portal. Portals go live progressively through the platform roadmap."
          tone="light"
        />
      </Container>
      <div className="mt-4">
        <PortalDirectory />
      </div>
    </div>
  );
}
