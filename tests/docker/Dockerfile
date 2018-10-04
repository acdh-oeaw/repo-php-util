FROM tomcat:9-jre8
MAINTAINER Mateusz Żółtak <mateusz.zoltak@oeaw.ac.at>

ENV FEDORA_VERSION="4.7.5" FEDORA_VARIANT=""

RUN cd /usr/local/tomcat/webapps &&\
  curl -s https://github.com/fcrepo4-exts/fcrepo-webapp-plus/releases/download/fcrepo-webapp-plus-${FEDORA_VERSION}/fcrepo-webapp-plus${FEDORA_VARIANT}-${FEDORA_VERSION}.war > /tmp/ROOT.war &&\
  rm -fR /usr/local/tomcat/webapps/ROOT &&\
  mkdir /usr/local/tomcat/webapps/ROOT &&\
  unzip /tmp/ROOT.war -d /usr/local/tomcat/webapps/ROOT &&\
  rm /tmp/ROOT.war

EXPOSE 8080
EXPOSE 61616
ENV CATALINA_OPTS="-Dfile.encoding=UTF-8 -Xms1024m -Xmx2048m -XX:MaxHeapFreeRatio=20 -XX:MinHeapFreeRatio=10 -XX:GCTimeRatio=20 -XX:MaxMetaspaceSize=512M" JAVA_OPTS="${JAVA_OPTS} -Djava.net.preferIPv4Stack=true -Dfcrepo.bnode.hash-uri=true -Dfcrepo.streaming.parallel=true"
