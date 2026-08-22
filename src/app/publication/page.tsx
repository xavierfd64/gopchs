import type { Metadata } from "next";
import { ComingSoon } from "@/components/ui/ComingSoon";

export const metadata: Metadata = {
  title: "School Publication",
  description: "The official Pura Central High School publication.",
};

export default function PublicationPage() {
  return (
    <ComingSoon
      title="School Publication"
      description="The Go PCHS School Publication — official student journalism and school news — is planned for a later development phase at publication.gopchs.com."
    />
  );
}
