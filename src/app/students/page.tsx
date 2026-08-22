import type { Metadata } from "next";
import { ComingSoon } from "@/components/ui/ComingSoon";

export const metadata: Metadata = {
  title: "Student Services",
  description: "Student services and support at Pura Central High School.",
};

export default function StudentsPage() {
  return (
    <ComingSoon
      title="Student Services"
      description="Guidance, library, clubs, and other student services will be detailed here in an upcoming development session. In the meantime, the Student Portal is on the Go PCHS roadmap."
      image={{
        src: "/assets/images/students/students-learning.jpg",
        alt: "PCHS students learning together",
      }}
    />
  );
}
