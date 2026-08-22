import type { Metadata } from "next";
import { Container } from "@/components/ui/Container";
import { SectionHeading } from "@/components/ui/SectionHeading";

export const metadata: Metadata = {
  title: "School History",
  description:
    "The founding story of Pura Central High School, from its opening in 2005 to the community effort that built it.",
};

const milestones = [
  {
    date: "June 6, 2005",
    title: "Pura Central High School opens",
    body: "The school opened at the Pura Central School site with eighty-six (86) first-year students in one section. There were no permanently assigned teachers yet — instructors from Estipona High School Main and Pura Central School were temporarily assigned to cover the different subjects. Classes were held at the Pura PPSTA Hall while a three-room school building was being constructed.",
  },
  {
    date: "July 12, 2005",
    title: "Mrs. De Pano deployed to the Annex",
    body: "Mrs. Joselinda P. De Pano was temporarily deployed to the Estipona High School Annex from B.S. Aquino NHS while her transfer was being processed. The class program was rearranged, with Mrs. De Pano handling most subjects except Science and Mathematics, assigned to Mr. Marcelo Esteban, head teacher of EHS. The Student Government Organization (SGO) was also organized during this period, with officers elected from president down to councilors.",
  },
  {
    date: "Mid-July 2005",
    title: "Reading assessment reveals a need to divide the class",
    body: "A series of English and Filipino reading lessons assessed the students' reading abilities. The results showed that almost half the class consisted of slow readers to non-readers, creating the need to divide the class into two sections.",
  },
  {
    date: "July 21, 2005",
    title: "Mrs. Gragasin joins the Annex",
    body: "Mrs. Gloria C. Gragasin from Vargas High School was assigned to the Estipona High School Annex, and the teaching schedule was adjusted again, dividing the remaining subjects between the two teachers.",
  },
  {
    date: "August 8, 2005",
    title: "The class is formally divided into two sections",
    body: "Section A, with 45 students, was assigned to Mrs. De Pano. Section B, with 40 students, was assigned to Mrs. Gragasin. Around this time, the PTA held meetings to address urgent school needs — parents and stakeholders volunteered their support, including help completing classroom windows.",
  },
  {
    date: "August 31, 2005",
    title: "PTA and SGO officers inducted during Buwan ng Wika",
    body: "PTA and SGO officers were inducted by the PSDS of Pura District, Miss Prescila I. Obligado, during the celebration of Buwan ng Wika. Through her efforts and the collective support of the community, the long-standing dream of the people of Pura to have a public high school within the town proper became a reality.",
  },
];

export default function HistoryPage() {
  return (
    <div className="py-14 sm:py-20">
      <Container>
        <SectionHeading
          eyebrow="About PCHS"
          title="Our History"
          description="Pura Central High School was built by its community, one school year at a time. Here is how it began."
        />

        <ol className="relative mt-12 space-y-10 border-l-2 border-pchs-gold-400/40 pl-8">
          {milestones.map((milestone) => (
            <li key={milestone.title} className="relative">
              <span className="absolute -left-[2.35rem] top-1 flex h-4 w-4 items-center justify-center rounded-full border-2 border-pchs-gold-500 bg-white" />
              <p className="text-xs font-bold uppercase tracking-wide text-pchs-gold-600">
                {milestone.date}
              </p>
              <h2 className="mt-1 font-display text-xl font-bold text-pchs-green-900">
                {milestone.title}
              </h2>
              <p className="mt-2 max-w-2xl text-sm leading-relaxed text-black/70">
                {milestone.body}
              </p>
            </li>
          ))}
        </ol>
      </Container>
    </div>
  );
}
