import Image from "next/image";
import { Container } from "@/components/ui/Container";
import { Badge } from "@/components/ui/Badge";

interface ComingSoonProps {
  title: string;
  description: string;
  image?: { src: string; alt: string };
}

export function ComingSoon({ title, description, image }: ComingSoonProps) {
  return (
    <div>
      {image && (
        <div className="relative aspect-[21/9] w-full overflow-hidden">
          <Image
            src={image.src}
            alt={image.alt}
            fill
            sizes="100vw"
            className="object-cover"
          />
        </div>
      )}
      <Container className="flex flex-col items-start gap-4 py-20">
        <Badge tone="gold">In Development</Badge>
        <h1 className="text-3xl font-bold text-pchs-green-900 sm:text-4xl">
          {title}
        </h1>
        <p className="max-w-2xl text-base leading-relaxed text-black/70">
          {description}
        </p>
      </Container>
    </div>
  );
}
